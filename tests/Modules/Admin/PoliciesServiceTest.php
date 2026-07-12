<?php

declare(strict_types=1);

namespace Tests\Modules\Admin;

use App\Core\Database;
use App\Modules\Admin\Services\PoliciesService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PoliciesService.
 *
 * Covers policy CRUD with validation, scope replacement and
 * normalisation, audience resolution via org_closure (empty scope =
 * all active members), acknowledgement stats, member-facing
 * outstanding/acknowledged queries, and the acknowledgement report.
 */
class PoliciesServiceTest extends TestCase
{
    private Database $db;
    private PoliciesService $service;
    private int $adminId;
    private int $levelTypeId;

    protected function setUp(): void
    {
        try {
            $this->db = new Database(TEST_CONFIG['db']);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        $this->dropTables();

        $this->db->query("
            CREATE TABLE `users` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `email` VARCHAR(255) NOT NULL UNIQUE,
                `password_hash` VARCHAR(255) NOT NULL DEFAULT '',
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE `org_level_types` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL,
                `depth` INT UNSIGNED NOT NULL DEFAULT 0,
                `is_leaf` TINYINT(1) NOT NULL DEFAULT 0,
                `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE `org_nodes` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `parent_id` INT UNSIGNED NULL,
                `level_type_id` INT UNSIGNED NOT NULL,
                `name` VARCHAR(200) NOT NULL,
                `short_name` VARCHAR(50) NULL,
                `description` TEXT NULL,
                `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT `fk_org_nodes_parent` FOREIGN KEY (`parent_id`) REFERENCES `org_nodes`(`id`) ON DELETE RESTRICT,
                CONSTRAINT `fk_org_nodes_level_type` FOREIGN KEY (`level_type_id`) REFERENCES `org_level_types`(`id`) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE `org_closure` (
                `ancestor_id` INT UNSIGNED NOT NULL,
                `descendant_id` INT UNSIGNED NOT NULL,
                `depth` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`ancestor_id`, `descendant_id`),
                CONSTRAINT `fk_org_closure_ancestor` FOREIGN KEY (`ancestor_id`) REFERENCES `org_nodes`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_org_closure_descendant` FOREIGN KEY (`descendant_id`) REFERENCES `org_nodes`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE `members` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NULL,
                `membership_number` VARCHAR(50) NOT NULL UNIQUE,
                `first_name` VARCHAR(100) NOT NULL,
                `surname` VARCHAR(100) NOT NULL,
                `email` VARCHAR(255) NULL,
                `status` ENUM('active', 'pending', 'suspended', 'inactive', 'left') NOT NULL DEFAULT 'pending',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT `fk_members_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE `member_nodes` (
                `member_id` INT UNSIGNED NOT NULL,
                `node_id` INT UNSIGNED NOT NULL,
                `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (`member_id`, `node_id`),
                CONSTRAINT `fk_member_nodes_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_member_nodes_node` FOREIGN KEY (`node_id`) REFERENCES `org_nodes`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE `policies` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(200) NOT NULL,
                `description` TEXT NULL,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_by` INT UNSIGNED NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_policy_active` (`is_active`),
                CONSTRAINT `fk_policy_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE `policy_scopes` (
                `policy_id` INT UNSIGNED NOT NULL,
                `node_id` INT UNSIGNED NOT NULL,
                PRIMARY KEY (`policy_id`, `node_id`),
                CONSTRAINT `fk_policy_scope_policy` FOREIGN KEY (`policy_id`) REFERENCES `policies` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_policy_scope_node` FOREIGN KEY (`node_id`) REFERENCES `org_nodes` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE `terms_versions` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `policy_id` INT UNSIGNED NOT NULL,
                `title` VARCHAR(300) NOT NULL,
                `content` LONGTEXT NOT NULL,
                `version_number` VARCHAR(20) NOT NULL,
                `is_published` TINYINT(1) NOT NULL DEFAULT 0,
                `published_at` DATETIME NULL,
                `grace_period_days` INT UNSIGNED NOT NULL DEFAULT 14,
                `created_by` INT UNSIGNED NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_terms_policy_pub` (`policy_id`, `is_published`),
                CONSTRAINT `fk_terms_policy` FOREIGN KEY (`policy_id`) REFERENCES `policies` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_terms_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE `terms_acceptances` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `terms_version_id` INT UNSIGNED NOT NULL,
                `user_id` INT UNSIGNED NOT NULL,
                `accepted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `ip_address` VARCHAR(45) NULL,
                UNIQUE KEY `uq_terms_user` (`terms_version_id`, `user_id`),
                CONSTRAINT `fk_acceptance_terms` FOREIGN KEY (`terms_version_id`) REFERENCES `terms_versions` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_acceptance_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->adminId = $this->db->insert('users', [
            'email' => 'admin@example.com',
            'password_hash' => password_hash('test', PASSWORD_BCRYPT),
        ]);

        $this->levelTypeId = $this->db->insert('org_level_types', ['name' => 'Group']);

        $this->service = new PoliciesService($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->dropTables();
        }
    }

    private function dropTables(): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (
            [
                'terms_acceptances', 'terms_versions', 'policy_scopes', 'policies',
                'member_nodes', 'members', 'org_closure', 'org_nodes',
                'org_level_types', 'users',
            ] as $table
        ) {
            $this->db->query("DROP TABLE IF EXISTS `$table`");
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    // ── Fixture helpers ──────────────────────────────────────────────────

    /**
     * Create an org node and maintain the closure table (self row plus
     * ancestor rows copied from the parent).
     */
    private function createNode(string $name, ?int $parentId = null): int
    {
        $id = $this->db->insert('org_nodes', [
            'parent_id' => $parentId,
            'level_type_id' => $this->levelTypeId,
            'name' => $name,
        ]);

        $this->db->insert('org_closure', ['ancestor_id' => $id, 'descendant_id' => $id, 'depth' => 0]);

        if ($parentId !== null) {
            $this->db->query(
                "INSERT INTO `org_closure` (`ancestor_id`, `descendant_id`, `depth`)
                 SELECT `ancestor_id`, ?, `depth` + 1 FROM `org_closure` WHERE `descendant_id` = ?",
                [$id, $parentId]
            );
        }

        return $id;
    }

    /**
     * Create a member, optionally with a linked user account and node
     * memberships.
     *
     * @param int[] $nodeIds
     */
    private function createMember(string $status = 'active', bool $withUser = true, array $nodeIds = []): array
    {
        static $seq = 0;
        $seq++;

        $userId = null;
        if ($withUser) {
            $userId = $this->db->insert('users', [
                'email' => "member{$seq}@example.com",
                'password_hash' => '',
            ]);
        }

        $memberId = $this->db->insert('members', [
            'user_id' => $userId,
            'membership_number' => "M{$seq}",
            'first_name' => "First{$seq}",
            'surname' => "Surname{$seq}",
            'email' => "member{$seq}@example.com",
            'status' => $status,
        ]);

        foreach ($nodeIds as $nodeId) {
            $this->db->insert('member_nodes', ['member_id' => $memberId, 'node_id' => $nodeId]);
        }

        return ['member_id' => $memberId, 'user_id' => $userId];
    }

    /**
     * Insert a terms version for a policy directly.
     */
    private function createVersion(int $policyId, bool $published, string $publishedAt = '2020-01-01 00:00:00', int $graceDays = 14): int
    {
        return $this->db->insert('terms_versions', [
            'policy_id' => $policyId,
            'title' => 'Terms',
            'content' => '<p>Content</p>',
            'version_number' => '1.0',
            'is_published' => $published ? 1 : 0,
            'published_at' => $published ? $publishedAt : null,
            'grace_period_days' => $graceDays,
            'created_by' => $this->adminId,
        ]);
    }

    // ── CRUD ─────────────────────────────────────────────────────────────

    public function testCreatePolicyStoresTrimmedFields(): void
    {
        $id = $this->service->createPolicy('  Code of Conduct  ', '  Behave.  ', $this->adminId);

        $policy = $this->service->getById($id);

        $this->assertSame('Code of Conduct', $policy['name']);
        $this->assertSame('Behave.', $policy['description']);
        $this->assertSame(1, (int) $policy['is_active']);
        $this->assertSame($this->adminId, (int) $policy['created_by']);
    }

    public function testCreatePolicyThrowsWhenNameIsBlank(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/name/i');

        $this->service->createPolicy('   ', null, $this->adminId);
    }

    public function testCreatePolicyDeduplicatesAndFiltersScopeNodeIds(): void
    {
        $nodeId = $this->createNode('Group A');

        $id = $this->service->createPolicy('Scoped', null, $this->adminId, [$nodeId, $nodeId, 0, -5]);

        $this->assertSame([$nodeId], $this->service->getScope($id));
    }

    public function testUpdatePolicyChangesNameAndReplacesScope(): void
    {
        $nodeA = $this->createNode('Group A');
        $nodeB = $this->createNode('Group B');
        $id = $this->service->createPolicy('Old Name', 'Desc', $this->adminId, [$nodeA]);

        $this->service->updatePolicy($id, 'New Name', null, [$nodeB]);

        $policy = $this->service->getById($id);
        $this->assertSame('New Name', $policy['name']);
        $this->assertNull($policy['description']);
        $this->assertSame([$nodeB], $this->service->getScope($id));
    }

    public function testUpdatePolicyThrowsWhenNameIsBlank(): void
    {
        $id = $this->service->createPolicy('Policy', null, $this->adminId);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->updatePolicy($id, '', null, []);
    }

    public function testSetActiveToggles(): void
    {
        $id = $this->service->createPolicy('Policy', null, $this->adminId);

        $this->service->setActive($id, false);
        $this->assertSame(0, (int) $this->service->getById($id)['is_active']);

        $this->service->setActive($id, true);
        $this->assertSame(1, (int) $this->service->getById($id)['is_active']);
    }

    public function testGetByIdReturnsNullForMissingPolicy(): void
    {
        $this->assertNull($this->service->getById(999999));
    }

    public function testGetAllOrdersActiveFirstThenByName(): void
    {
        $inactive = $this->service->createPolicy('Alpha', null, $this->adminId);
        $this->service->setActive($inactive, false);
        $this->service->createPolicy('Zulu', null, $this->adminId);
        $this->service->createPolicy('Bravo', null, $this->adminId);

        $names = array_column($this->service->getAll(), 'name');

        $this->assertSame(['Bravo', 'Zulu', 'Alpha'], $names);
    }

    public function testGetScopeReturnsEmptyArrayWhenUnscoped(): void
    {
        $id = $this->service->createPolicy('Global', null, $this->adminId);

        $this->assertSame([], $this->service->getScope($id));
    }

    // ── getRequiredMemberIds() ───────────────────────────────────────────

    public function testGetRequiredMemberIdsWithEmptyScopeReturnsAllActiveMembers(): void
    {
        $active = $this->createMember('active');
        $pending = $this->createMember('pending');
        $left = $this->createMember('left');
        $policyId = $this->service->createPolicy('Global', null, $this->adminId);

        $ids = $this->service->getRequiredMemberIds($policyId);

        $this->assertContains($active['member_id'], $ids);
        $this->assertNotContains($pending['member_id'], $ids);
        $this->assertNotContains($left['member_id'], $ids);
    }

    public function testGetRequiredMemberIdsResolvesScopeThroughClosureDescendants(): void
    {
        $region = $this->createNode('Region');
        $group = $this->createNode('Group', $region);
        $otherGroup = $this->createNode('Other Group');

        $inScope = $this->createMember('active', true, [$group]);
        $outOfScope = $this->createMember('active', true, [$otherGroup]);
        $noNode = $this->createMember('active');

        // Scope on the region: member in the child group qualifies
        $policyId = $this->service->createPolicy('Regional', null, $this->adminId, [$region]);

        $ids = $this->service->getRequiredMemberIds($policyId);

        $this->assertContains($inScope['member_id'], $ids);
        $this->assertNotContains($outOfScope['member_id'], $ids);
        $this->assertNotContains($noNode['member_id'], $ids);
    }

    public function testGetRequiredMemberIdsExcludesInactiveMembersInScope(): void
    {
        $group = $this->createNode('Group');
        $inactive = $this->createMember('inactive', true, [$group]);
        $policyId = $this->service->createPolicy('Scoped', null, $this->adminId, [$group]);

        $this->assertNotContains($inactive['member_id'], $this->service->getRequiredMemberIds($policyId));
    }

    // ── getStats() ───────────────────────────────────────────────────────

    public function testGetStatsWithoutPublishedVersionReportsZeroAcknowledged(): void
    {
        $this->createMember('active');
        $policyId = $this->service->createPolicy('Global', null, $this->adminId);
        $this->createVersion($policyId, false);

        $stats = $this->service->getStats($policyId);

        $this->assertSame(1, $stats['required']);
        $this->assertSame(0, $stats['acknowledged']);
        $this->assertSame(0.0, $stats['rate']);
        $this->assertNull($stats['published_version_id']);
    }

    public function testGetStatsComputesAcknowledgementRate(): void
    {
        $acker = $this->createMember('active');
        $this->createMember('active');
        $policyId = $this->service->createPolicy('Global', null, $this->adminId);
        $versionId = $this->createVersion($policyId, true);

        $this->db->insert('terms_acceptances', [
            'terms_version_id' => $versionId,
            'user_id' => $acker['user_id'],
        ]);

        $stats = $this->service->getStats($policyId);

        $this->assertSame(2, $stats['required']);
        $this->assertSame(1, $stats['acknowledged']);
        $this->assertSame(50.0, $stats['rate']);
        $this->assertSame($versionId, (int) $stats['published_version_id']);
    }

    // ── getOutstandingForMember() / getAcknowledgedForMember() ───────────

    public function testGetOutstandingForMemberListsUnacknowledgedPolicy(): void
    {
        $member = $this->createMember('active');
        $policyId = $this->service->createPolicy('Global', null, $this->adminId);
        $versionId = $this->createVersion($policyId, true, '2020-01-01 00:00:00', 14);

        $outstanding = $this->service->getOutstandingForMember($member['member_id']);

        $this->assertCount(1, $outstanding);
        $this->assertSame($policyId, $outstanding[0]['policy_id']);
        $this->assertSame($versionId, $outstanding[0]['version_id']);
        $this->assertNull($outstanding[0]['accepted_at']);
        $this->assertTrue($outstanding[0]['is_overdue']);
    }

    public function testGetOutstandingForMemberIsNotOverdueDuringGracePeriod(): void
    {
        $member = $this->createMember('active');
        $policyId = $this->service->createPolicy('Global', null, $this->adminId);
        $this->createVersion($policyId, true, gmdate('Y-m-d H:i:s'), 365);

        $outstanding = $this->service->getOutstandingForMember($member['member_id']);

        $this->assertCount(1, $outstanding);
        $this->assertFalse($outstanding[0]['is_overdue']);
    }

    public function testGetOutstandingForMemberSkipsPoliciesWithoutPublishedVersion(): void
    {
        $member = $this->createMember('active');
        $policyId = $this->service->createPolicy('Draft Only', null, $this->adminId);
        $this->createVersion($policyId, false);

        $this->assertSame([], $this->service->getOutstandingForMember($member['member_id']));
    }

    public function testGetOutstandingForMemberSkipsOutOfScopeMember(): void
    {
        $group = $this->createNode('Group');
        $member = $this->createMember('active'); // not in any node
        $policyId = $this->service->createPolicy('Scoped', null, $this->adminId, [$group]);
        $this->createVersion($policyId, true);

        $this->assertSame([], $this->service->getOutstandingForMember($member['member_id']));
    }

    public function testGetOutstandingForMemberReturnsEmptyForMemberWithoutUser(): void
    {
        $member = $this->createMember('active', false);
        $policyId = $this->service->createPolicy('Global', null, $this->adminId);
        $this->createVersion($policyId, true);

        $this->assertSame([], $this->service->getOutstandingForMember($member['member_id']));
    }

    public function testGetOutstandingForMemberReturnsEmptyForUnknownMember(): void
    {
        $this->assertSame([], $this->service->getOutstandingForMember(999999));
    }

    public function testGetAcknowledgedForMemberListsAcceptedPolicy(): void
    {
        $member = $this->createMember('active');
        $policyId = $this->service->createPolicy('Global', null, $this->adminId);
        $versionId = $this->createVersion($policyId, true);

        $this->db->insert('terms_acceptances', [
            'terms_version_id' => $versionId,
            'user_id' => $member['user_id'],
        ]);

        $acknowledged = $this->service->getAcknowledgedForMember($member['member_id']);

        $this->assertCount(1, $acknowledged);
        $this->assertSame($policyId, $acknowledged[0]['policy_id']);
        $this->assertNotNull($acknowledged[0]['accepted_at']);
        $this->assertFalse($acknowledged[0]['is_overdue']);

        // Once acknowledged the policy is no longer outstanding
        $this->assertSame([], $this->service->getOutstandingForMember($member['member_id']));
    }

    // ── getAcknowledgementReport() ───────────────────────────────────────

    public function testGetAcknowledgementReportFlagsAcknowledgedMembers(): void
    {
        $acker = $this->createMember('active');
        $slacker = $this->createMember('active');
        $policyId = $this->service->createPolicy('Global', null, $this->adminId);
        $versionId = $this->createVersion($policyId, true);

        $this->db->insert('terms_acceptances', [
            'terms_version_id' => $versionId,
            'user_id' => $acker['user_id'],
        ]);

        $report = $this->service->getAcknowledgementReport($policyId);

        $this->assertCount(2, $report);
        // Unacknowledged members are listed first
        $this->assertSame($slacker['member_id'], (int) $report[0]['id']);
        $this->assertSame(0, (int) $report[0]['acknowledged']);
        $this->assertNull($report[0]['accepted_at']);
        $this->assertSame($acker['member_id'], (int) $report[1]['id']);
        $this->assertSame(1, (int) $report[1]['acknowledged']);
        $this->assertNotNull($report[1]['accepted_at']);
    }

    public function testGetAcknowledgementReportWithoutPublishedVersionMarksNobodyAcknowledged(): void
    {
        $this->createMember('active');
        $policyId = $this->service->createPolicy('Global', null, $this->adminId);
        $this->createVersion($policyId, false);

        $report = $this->service->getAcknowledgementReport($policyId);

        $this->assertCount(1, $report);
        $this->assertSame(0, (int) $report[0]['acknowledged']);
        $this->assertNull($report[0]['accepted_at']);
    }

    public function testGetAcknowledgementReportReturnsEmptyWhenNobodyIsRequired(): void
    {
        $policyId = $this->service->createPolicy('Global', null, $this->adminId);
        $this->createVersion($policyId, true);

        $this->assertSame([], $this->service->getAcknowledgementReport($policyId));
    }
}

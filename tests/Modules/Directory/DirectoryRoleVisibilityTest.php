<?php

declare(strict_types=1);

namespace Tests\Modules\Directory;

use App\Core\Database;
use App\Modules\Directory\Services\DirectoryService;
use PHPUnit\Framework\TestCase;

/**
 * Role aggregation and visibility-window tests for DirectoryService.
 *
 * Complements DirectoryServiceTest / DirectoryScopingTest, which cover
 * scope filtering, free-text search, and node filters. This file covers
 * the gaps:
 *
 *  - getDirectoryMembers(): the role_names GROUP_CONCAT column —
 *    aggregation, alphabetical ordering, de-duplication across contexts,
 *    date-window filtering (expired / future / ending-today), and NULL
 *    when a member holds no roles or has no linked user
 *  - getDirectoryMembers(): member deduplication when linked to several
 *    in-scope nodes; NULL primary_node_name when no primary flag
 *  - getContactDirectory(): assignment date-window filtering, exclusion
 *    of team-context assignments, ordering, and scope+node_id combined
 */
class DirectoryRoleVisibilityTest extends TestCase
{
    private Database $db;
    private DirectoryService $svc;

    private int $nodeAlpha;
    private int $nodeBeta;
    private int $roleLeader;
    private int $roleTreasurer;

    protected function setUp(): void
    {
        try {
            $this->db = new Database(TEST_CONFIG['db']);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
        foreach (['role_assignments', 'roles', 'member_nodes', 'members', 'users', 'org_nodes'] as $t) {
            $this->db->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");

        $this->db->query("CREATE TABLE `org_nodes` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(200) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `users` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `email` VARCHAR(255) NOT NULL UNIQUE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `members` (
            `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `first_name` VARCHAR(100) NOT NULL,
            `surname`    VARCHAR(100) NOT NULL,
            `email`      VARCHAR(255) NULL,
            `phone`      VARCHAR(50)  NULL,
            `photo_path` VARCHAR(500) NULL,
            `status`     VARCHAR(20)  NOT NULL DEFAULT 'active',
            `user_id`    INT UNSIGNED NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `member_nodes` (
            `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `member_id`  INT UNSIGNED NOT NULL,
            `node_id`    INT UNSIGNED NOT NULL,
            `is_primary` TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `roles` (
            `id`                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name`                 VARCHAR(100) NOT NULL,
            `is_directory_visible` TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `role_assignments` (
            `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id`      INT UNSIGNED NOT NULL,
            `role_id`      INT UNSIGNED NOT NULL,
            `context_type` ENUM('node','team') NOT NULL DEFAULT 'node',
            `context_id`   INT UNSIGNED NULL,
            `start_date`   DATE NOT NULL,
            `end_date`     DATE NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->nodeAlpha = $this->db->insert('org_nodes', ['name' => 'Alpha Troop']);
        $this->nodeBeta  = $this->db->insert('org_nodes', ['name' => 'Beta Pack']);

        $this->roleLeader    = $this->db->insert('roles', ['name' => 'Leader', 'is_directory_visible' => 1]);
        $this->roleTreasurer = $this->db->insert('roles', ['name' => 'Treasurer', 'is_directory_visible' => 1]);

        $this->svc = new DirectoryService($this->db);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
        foreach (['role_assignments', 'roles', 'member_nodes', 'members', 'users', 'org_nodes'] as $t) {
            $this->db->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");
    }

    // ── Seed helpers ──────────────────────────────────────────────────────

    /**
     * Create an active member linked to a node (as primary by default).
     * Returns [memberId, userId|null].
     */
    private function createMember(
        string $firstName,
        string $surname,
        int $nodeId,
        bool $withUser = true,
        bool $isPrimary = true
    ): array {
        $userId = null;
        if ($withUser) {
            $email = strtolower($firstName . '.' . $surname) . '@example.com';
            $userId = $this->db->insert('users', ['email' => $email]);
        }

        $memberId = $this->db->insert('members', [
            'first_name' => $firstName,
            'surname'    => $surname,
            'email'      => strtolower($firstName) . '@example.com',
            'status'     => 'active',
            'user_id'    => $userId,
        ]);

        $this->db->insert('member_nodes', [
            'member_id'  => $memberId,
            'node_id'    => $nodeId,
            'is_primary' => $isPrimary ? 1 : 0,
        ]);

        return [$memberId, $userId];
    }

    private function assignRole(
        int $userId,
        int $roleId,
        int $contextId,
        string $startDate = '2020-01-01',
        ?string $endDate = null,
        string $contextType = 'node'
    ): void {
        $this->db->insert('role_assignments', [
            'user_id'      => $userId,
            'role_id'      => $roleId,
            'context_type' => $contextType,
            'context_id'   => $contextId,
            'start_date'   => $startDate,
            'end_date'     => $endDate,
        ]);
    }

    // ── getDirectoryMembers(): role_names aggregation ────────────────────

    public function testRoleNamesAggregatesMultipleCurrentRolesAlphabetically(): void
    {
        [, $userId] = $this->createMember('Alice', 'Anderson', $this->nodeAlpha);
        $this->assignRole($userId, $this->roleTreasurer, $this->nodeAlpha);
        $this->assignRole($userId, $this->roleLeader, $this->nodeAlpha);

        $rows = $this->svc->getDirectoryMembers([$this->nodeAlpha]);

        $this->assertCount(1, $rows);
        $this->assertSame('Leader, Treasurer', $rows[0]['role_names']);
    }

    public function testRoleNamesExcludesExpiredAssignments(): void
    {
        [, $userId] = $this->createMember('Alice', 'Anderson', $this->nodeAlpha);
        $this->assignRole($userId, $this->roleLeader, $this->nodeAlpha);
        $this->assignRole($userId, $this->roleTreasurer, $this->nodeAlpha, '2019-01-01', '2021-12-31');

        $rows = $this->svc->getDirectoryMembers([$this->nodeAlpha]);

        $this->assertSame('Leader', $rows[0]['role_names']);
    }

    public function testRoleNamesExcludesFutureAssignments(): void
    {
        [, $userId] = $this->createMember('Alice', 'Anderson', $this->nodeAlpha);
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $this->assignRole($userId, $this->roleLeader, $this->nodeAlpha, $tomorrow);

        $rows = $this->svc->getDirectoryMembers([$this->nodeAlpha]);

        $this->assertNull($rows[0]['role_names']);
    }

    public function testRoleNamesIncludesAssignmentEndingToday(): void
    {
        [, $userId] = $this->createMember('Alice', 'Anderson', $this->nodeAlpha);
        $today = date('Y-m-d');
        $this->assignRole($userId, $this->roleLeader, $this->nodeAlpha, '2020-01-01', $today);

        $rows = $this->svc->getDirectoryMembers([$this->nodeAlpha]);

        $this->assertSame('Leader', $rows[0]['role_names']);
    }

    public function testRoleNamesDeduplicatesSameRoleHeldInMultipleContexts(): void
    {
        [, $userId] = $this->createMember('Alice', 'Anderson', $this->nodeAlpha);
        $this->assignRole($userId, $this->roleLeader, $this->nodeAlpha);
        $this->assignRole($userId, $this->roleLeader, $this->nodeBeta);

        $rows = $this->svc->getDirectoryMembers([$this->nodeAlpha]);

        $this->assertSame('Leader', $rows[0]['role_names']);
    }

    public function testRoleNamesNullWhenMemberHoldsNoRoles(): void
    {
        $this->createMember('Alice', 'Anderson', $this->nodeAlpha);

        $rows = $this->svc->getDirectoryMembers([$this->nodeAlpha]);

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['role_names']);
    }

    public function testMemberWithoutLinkedUserStillListedWithNullRoleNames(): void
    {
        $this->createMember('Nolan', 'Nouser', $this->nodeAlpha, false);

        $rows = $this->svc->getDirectoryMembers([$this->nodeAlpha]);

        $this->assertCount(1, $rows);
        $this->assertSame('Nolan Nouser', $rows[0]['member_name']);
        $this->assertNull($rows[0]['role_names']);
    }

    public function testDirectoryIncludesHiddenRolesInRoleNames(): void
    {
        // getDirectoryMembers() does not filter by is_directory_visible —
        // that flag only applies to the legacy getContactDirectory().
        // This test documents that behaviour.
        $hiddenRole = $this->db->insert('roles', ['name' => 'Helper', 'is_directory_visible' => 0]);
        [, $userId] = $this->createMember('Alice', 'Anderson', $this->nodeAlpha);
        $this->assignRole($userId, $hiddenRole, $this->nodeAlpha);

        $rows = $this->svc->getDirectoryMembers([$this->nodeAlpha]);

        $this->assertSame('Helper', $rows[0]['role_names']);
    }

    // ── getDirectoryMembers(): dedup and primary node ────────────────────

    public function testMemberLinkedToMultipleScopedNodesAppearsOnce(): void
    {
        [$memberId] = $this->createMember('Alice', 'Anderson', $this->nodeAlpha);
        $this->db->insert('member_nodes', [
            'member_id'  => $memberId,
            'node_id'    => $this->nodeBeta,
            'is_primary' => 0,
        ]);

        $rows = $this->svc->getDirectoryMembers([$this->nodeAlpha, $this->nodeBeta]);

        $this->assertCount(1, $rows);
    }

    public function testPrimaryNodeNameNullWhenNoPrimaryFlagSet(): void
    {
        $this->createMember('Alice', 'Anderson', $this->nodeAlpha, true, false);

        $rows = $this->svc->getDirectoryMembers([$this->nodeAlpha]);

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['primary_node_name']);
    }

    // ── getContactDirectory(): assignment date window ────────────────────

    public function testContactDirectoryExcludesExpiredAssignments(): void
    {
        [, $userId] = $this->createMember('Erin', 'Expired', $this->nodeAlpha);
        $this->assignRole($userId, $this->roleLeader, $this->nodeAlpha, '2019-01-01', '2021-12-31');

        $this->assertSame([], $this->svc->getContactDirectory());
    }

    public function testContactDirectoryExcludesFutureAssignments(): void
    {
        [, $userId] = $this->createMember('Fiona', 'Future', $this->nodeAlpha);
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $this->assignRole($userId, $this->roleLeader, $this->nodeAlpha, $tomorrow);

        $this->assertSame([], $this->svc->getContactDirectory());
    }

    public function testContactDirectoryIncludesAssignmentEndingToday(): void
    {
        [, $userId] = $this->createMember('Tina', 'Today', $this->nodeAlpha);
        $this->assignRole($userId, $this->roleLeader, $this->nodeAlpha, '2020-01-01', date('Y-m-d'));

        $contacts = $this->svc->getContactDirectory();

        $this->assertCount(1, $contacts);
        $this->assertSame('Tina Today', $contacts[0]['member_name']);
    }

    public function testContactDirectoryExcludesTeamContextAssignments(): void
    {
        [, $userId] = $this->createMember('Tom', 'Teammate', $this->nodeAlpha);
        $this->assignRole($userId, $this->roleLeader, $this->nodeAlpha, '2020-01-01', null, 'team');

        $this->assertSame([], $this->svc->getContactDirectory());
    }

    // ── getContactDirectory(): ordering and combined filters ─────────────

    public function testContactDirectoryOrderedByNodeThenRoleThenSurname(): void
    {
        // Beta Pack sorts after Alpha Troop; within a node, Leader < Treasurer
        [, $uZoe]  = $this->createMember('Zoe', 'Zimmer', $this->nodeAlpha);
        [, $uAmy]  = $this->createMember('Amy', 'Able', $this->nodeAlpha);
        [, $uBen]  = $this->createMember('Ben', 'Baker', $this->nodeBeta);

        $this->assignRole($uZoe, $this->roleLeader, $this->nodeAlpha);
        $this->assignRole($uAmy, $this->roleTreasurer, $this->nodeAlpha);
        $this->assignRole($uBen, $this->roleLeader, $this->nodeBeta);

        $contacts = $this->svc->getContactDirectory();

        $ordered = array_map(
            static fn(array $c): string => $c['node_name'] . '/' . $c['role_name'] . '/' . $c['member_name'],
            $contacts
        );

        $this->assertSame([
            'Alpha Troop/Leader/Zoe Zimmer',
            'Alpha Troop/Treasurer/Amy Able',
            'Beta Pack/Leader/Ben Baker',
        ], $ordered);
    }

    public function testContactDirectoryScopeAndNodeIdMustBothMatch(): void
    {
        [, $uAmy] = $this->createMember('Amy', 'Able', $this->nodeAlpha);
        [, $uBen] = $this->createMember('Ben', 'Baker', $this->nodeBeta);
        $this->assignRole($uAmy, $this->roleLeader, $this->nodeAlpha);
        $this->assignRole($uBen, $this->roleLeader, $this->nodeBeta);

        // node_id inside scope → match
        $inScope = $this->svc->getContactDirectory($this->nodeAlpha, null, [$this->nodeAlpha]);
        $this->assertSame(['Amy Able'], array_column($inScope, 'member_name'));

        // node_id outside the caller's scope → no results (conditions are ANDed)
        $outOfScope = $this->svc->getContactDirectory($this->nodeBeta, null, [$this->nodeAlpha]);
        $this->assertSame([], $outOfScope);
    }
}

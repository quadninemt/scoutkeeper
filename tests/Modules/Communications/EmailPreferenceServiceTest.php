<?php

declare(strict_types=1);

namespace Tests\Modules\Communications;

use App\Core\Database;
use App\Modules\Communications\Services\EmailPreferenceService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for EmailPreferenceService.
 *
 * Covers getPreferences(), setPreference() (insert + update paths),
 * isOptedIn() (default, explicit opt-in/out, bounced), recordBounce()
 * (threshold, idempotency), and getOptedInMembers() (filtering,
 * opt-out exclusion, bounce exclusion, node scoping).
 */
class EmailPreferenceServiceTest extends TestCase
{
    private Database $db;
    private EmailPreferenceService $service;

    /** Member IDs created in setUp for reuse across tests. */
    private int $memberAlice;
    private int $memberBob;
    private int $memberCarol;

    // ── Setup / Teardown ──────────────────────────────────────────────

    protected function setUp(): void
    {
        try {
            $this->db = new Database(TEST_CONFIG['db']);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
        foreach (
            [
                'member_email_preferences',
                'member_nodes',
                'members',
                'org_level_types',
                'org_nodes',
                'users',
            ] as $table
        ) {
            $this->db->query("DROP TABLE IF EXISTS `{$table}`");
        }
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");

        // Minimal users table (needed only to satisfy FK direction — not used in logic)
        $this->db->query("
            CREATE TABLE `users` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `email` VARCHAR(255) NOT NULL UNIQUE,
                `password_hash` VARCHAR(255) NOT NULL DEFAULT '',
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `is_super_admin` TINYINT(1) NOT NULL DEFAULT 0,
                `mfa_secret` TEXT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // org_level_types needed by org_nodes FK
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
                `age_group_min` TINYINT UNSIGNED NULL,
                `age_group_max` TINYINT UNSIGNED NULL,
                `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT `fk_nodes_level_type` FOREIGN KEY (`level_type_id`) REFERENCES `org_level_types` (`id`) ON DELETE RESTRICT
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
                `status` ENUM('active','pending','suspended','inactive','left') NOT NULL DEFAULT 'pending',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FULLTEXT INDEX `ft_members_search` (`first_name`, `surname`, `email`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE `member_nodes` (
                `member_id` INT UNSIGNED NOT NULL,
                `node_id` INT UNSIGNED NOT NULL,
                `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (`member_id`, `node_id`),
                CONSTRAINT `fk_mn_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_mn_node` FOREIGN KEY (`node_id`) REFERENCES `org_nodes` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE `member_email_preferences` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `member_id` INT UNSIGNED NOT NULL,
                `email_type` VARCHAR(50) NOT NULL DEFAULT 'general',
                `is_opted_in` TINYINT(1) NOT NULL DEFAULT 1,
                `bounced` TINYINT(1) NOT NULL DEFAULT 0,
                `bounce_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                UNIQUE KEY `uq_member_email_type` (`member_id`, `email_type`),
                CONSTRAINT `fk_email_pref_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Seed three active members with email addresses
        $this->memberAlice = $this->insertMember('alice@example.com', 'Alice', 'Anzalone');
        $this->memberBob   = $this->insertMember('bob@example.com',   'Bob',   'Borg');
        $this->memberCarol = $this->insertMember('carol@example.com', 'Carol', 'Cauchi');

        $this->service = new EmailPreferenceService($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
            foreach (
                [
                    'member_email_preferences',
                    'member_nodes',
                    'members',
                    'org_nodes',
                    'org_level_types',
                    'users',
                ] as $table
            ) {
                $this->db->query("DROP TABLE IF EXISTS `{$table}`");
            }
            $this->db->query("SET FOREIGN_KEY_CHECKS = 1");
        }
    }

    // ── getPreferences() ──────────────────────────────────────────────

    public function testGetPreferencesReturnsEmptyWhenNoneSet(): void
    {
        $prefs = $this->service->getPreferences($this->memberAlice);
        $this->assertSame([], $prefs);
    }

    public function testGetPreferencesReturnsAllRowsForMember(): void
    {
        $this->service->setPreference($this->memberAlice, 'general', true);
        $this->service->setPreference($this->memberAlice, 'newsletter', false);

        $prefs = $this->service->getPreferences($this->memberAlice);

        $this->assertCount(2, $prefs);
        $types = array_column($prefs, 'email_type');
        $this->assertContains('general', $types);
        $this->assertContains('newsletter', $types);
    }

    public function testGetPreferencesDoesNotReturnOtherMembersRows(): void
    {
        $this->service->setPreference($this->memberBob, 'general', false);

        $prefs = $this->service->getPreferences($this->memberAlice);
        $this->assertSame([], $prefs);
    }

    // ── setPreference() ───────────────────────────────────────────────

    public function testSetPreferenceInsertsNewRow(): void
    {
        $this->service->setPreference($this->memberAlice, 'general', true);

        $row = $this->db->fetchOne(
            "SELECT * FROM member_email_preferences
             WHERE member_id = :mid AND email_type = 'general'",
            ['mid' => $this->memberAlice]
        );

        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['is_opted_in']);
    }

    public function testSetPreferenceUpdatesExistingRow(): void
    {
        $this->service->setPreference($this->memberAlice, 'general', true);
        $this->service->setPreference($this->memberAlice, 'general', false);

        $rows = $this->db->fetchAll(
            "SELECT * FROM member_email_preferences
             WHERE member_id = :mid AND email_type = 'general'",
            ['mid' => $this->memberAlice]
        );

        $this->assertCount(1, $rows, 'Must update, not insert a duplicate row');
        $this->assertSame(0, (int) $rows[0]['is_opted_in']);
    }

    public function testSetPreferenceOptInStoredCorrectly(): void
    {
        $this->service->setPreference($this->memberAlice, 'newsletter', true);

        $row = $this->db->fetchOne(
            "SELECT is_opted_in FROM member_email_preferences
             WHERE member_id = :mid AND email_type = 'newsletter'",
            ['mid' => $this->memberAlice]
        );

        $this->assertSame(1, (int) $row['is_opted_in']);
    }

    public function testSetPreferenceOptOutStoredCorrectly(): void
    {
        $this->service->setPreference($this->memberBob, 'newsletter', false);

        $row = $this->db->fetchOne(
            "SELECT is_opted_in FROM member_email_preferences
             WHERE member_id = :mid AND email_type = 'newsletter'",
            ['mid' => $this->memberBob]
        );

        $this->assertSame(0, (int) $row['is_opted_in']);
    }

    // ── isOptedIn() ───────────────────────────────────────────────────

    public function testIsOptedInReturnsTrueWhenNoPreferenceExists(): void
    {
        // No preference row — default is opted-in
        $result = $this->service->isOptedIn($this->memberAlice, 'general');
        $this->assertTrue($result);
    }

    public function testIsOptedInReturnsTrueForExplicitOptIn(): void
    {
        $this->service->setPreference($this->memberAlice, 'general', true);
        $this->assertTrue($this->service->isOptedIn($this->memberAlice, 'general'));
    }

    public function testIsOptedInReturnsFalseForExplicitOptOut(): void
    {
        $this->service->setPreference($this->memberBob, 'general', false);
        $this->assertFalse($this->service->isOptedIn($this->memberBob, 'general'));
    }

    public function testIsOptedInReturnsFalseForBouncedMember(): void
    {
        // Insert a bounced preference row directly
        $this->db->insert('member_email_preferences', [
            'member_id'  => $this->memberCarol,
            'email_type' => 'general',
            'is_opted_in' => 1,
            'bounced'     => 1,
            'bounce_count' => 3,
        ]);

        $this->assertFalse($this->service->isOptedIn($this->memberCarol, 'general'));
    }

    public function testIsOptedInDefaultEmailTypeIsGeneral(): void
    {
        // No row exists → should default to opted-in
        $this->assertTrue($this->service->isOptedIn($this->memberAlice));
    }

    public function testIsOptedInIsScopedByEmailType(): void
    {
        $this->service->setPreference($this->memberAlice, 'newsletter', false);

        // opted out of newsletter but no preference for general → general is still opted-in
        $this->assertFalse($this->service->isOptedIn($this->memberAlice, 'newsletter'));
        $this->assertTrue($this->service->isOptedIn($this->memberAlice, 'general'));
    }

    // ── recordBounce() ────────────────────────────────────────────────

    public function testRecordBounceCreatesRowWhenNoneExists(): void
    {
        $this->service->recordBounce($this->memberAlice);

        $row = $this->db->fetchOne(
            "SELECT * FROM member_email_preferences
             WHERE member_id = :mid AND email_type = 'general'",
            ['mid' => $this->memberAlice]
        );

        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['bounce_count']);
        $this->assertSame(0, (int) $row['bounced'], 'One bounce should not set bounced flag');
    }

    public function testRecordBounceIncrementsCount(): void
    {
        $this->service->recordBounce($this->memberBob);
        $this->service->recordBounce($this->memberBob);

        $row = $this->db->fetchOne(
            "SELECT bounce_count FROM member_email_preferences
             WHERE member_id = :mid AND email_type = 'general'",
            ['mid' => $this->memberBob]
        );

        $this->assertSame(2, (int) $row['bounce_count']);
    }

    public function testRecordBounceSetsBouncedflagAtThreshold(): void
    {
        // BOUNCE_THRESHOLD = 3
        $this->service->recordBounce($this->memberCarol);
        $this->service->recordBounce($this->memberCarol);

        $before = $this->db->fetchOne(
            "SELECT bounced FROM member_email_preferences
             WHERE member_id = :mid AND email_type = 'general'",
            ['mid' => $this->memberCarol]
        );
        $this->assertSame(0, (int) $before['bounced'], 'Two bounces should not set flag yet');

        $this->service->recordBounce($this->memberCarol);

        $after = $this->db->fetchOne(
            "SELECT bounced, bounce_count FROM member_email_preferences
             WHERE member_id = :mid AND email_type = 'general'",
            ['mid' => $this->memberCarol]
        );
        $this->assertSame(1, (int) $after['bounced'], 'Three bounces must set bounced = 1');
        $this->assertSame(3, (int) $after['bounce_count']);
    }

    public function testRecordBounceOnlyAffectsGeneralEmailType(): void
    {
        $this->service->setPreference($this->memberAlice, 'newsletter', true);
        $this->service->recordBounce($this->memberAlice);

        // newsletter row must be untouched
        $newsletterRow = $this->db->fetchOne(
            "SELECT bounce_count FROM member_email_preferences
             WHERE member_id = :mid AND email_type = 'newsletter'",
            ['mid' => $this->memberAlice]
        );
        $this->assertSame(0, (int) $newsletterRow['bounce_count']);
    }

    // ── getOptedInMembers() ───────────────────────────────────────────

    public function testGetOptedInMembersReturnsAllActiveByDefault(): void
    {
        $members = $this->service->getOptedInMembers('general');

        $emails = array_column($members, 'email');
        $this->assertContains('alice@example.com', $emails);
        $this->assertContains('bob@example.com', $emails);
        $this->assertContains('carol@example.com', $emails);
    }

    public function testGetOptedInMembersExcludesExplicitOptOut(): void
    {
        $this->service->setPreference($this->memberBob, 'general', false);

        $members = $this->service->getOptedInMembers('general');
        $emails = array_column($members, 'email');

        $this->assertNotContains('bob@example.com', $emails);
        $this->assertContains('alice@example.com', $emails);
        $this->assertContains('carol@example.com', $emails);
    }

    public function testGetOptedInMembersExcludesBouncedMembers(): void
    {
        $this->db->insert('member_email_preferences', [
            'member_id'   => $this->memberCarol,
            'email_type'  => 'general',
            'is_opted_in' => 1,
            'bounced'     => 1,
            'bounce_count' => 3,
        ]);

        $members = $this->service->getOptedInMembers('general');
        $emails = array_column($members, 'email');

        $this->assertNotContains('carol@example.com', $emails);
    }

    public function testGetOptedInMembersIncludesMembersWithNoPreferenceRow(): void
    {
        // Alice has no preference row at all — should be included (default = opted in)
        $members = $this->service->getOptedInMembers('general');
        $emails = array_column($members, 'email');

        $this->assertContains('alice@example.com', $emails);
    }

    public function testGetOptedInMembersIsScopedByEmailType(): void
    {
        $this->service->setPreference($this->memberBob, 'newsletter', false);

        $generalMembers = $this->service->getOptedInMembers('general');
        $generalEmails = array_column($generalMembers, 'email');
        $this->assertContains('bob@example.com', $generalEmails, 'Bob not opted out of general');

        $newsletterMembers = $this->service->getOptedInMembers('newsletter');
        $newsletterEmails = array_column($newsletterMembers, 'email');
        $this->assertNotContains('bob@example.com', $newsletterEmails);
    }

    public function testGetOptedInMembersExcludesInactiveMembers(): void
    {
        // Add an inactive member with email
        $inactiveId = $this->insertMember('inactive@example.com', 'Dave', 'Dalli', 'inactive');

        $members = $this->service->getOptedInMembers('general');
        $emails = array_column($members, 'email');

        $this->assertNotContains('inactive@example.com', $emails);

        // Cleanup
        $this->db->query("DELETE FROM members WHERE id = :id", ['id' => $inactiveId]);
    }

    public function testGetOptedInMembersExcludesMembersWithNoEmail(): void
    {
        // Insert a member with a NULL email
        $noEmailId = $this->db->insert('members', [
            'membership_number' => 'SK-NO-EMAIL',
            'first_name'        => 'Eve',
            'surname'           => 'Ellul',
            'email'             => null,
            'status'            => 'active',
        ]);

        $members = $this->service->getOptedInMembers('general');
        $memberIds = array_column($members, 'member_id');

        $this->assertNotContains($noEmailId, $memberIds);

        // Cleanup
        $this->db->query("DELETE FROM members WHERE id = :id", ['id' => $noEmailId]);
    }

    public function testGetOptedInMembersFiltersByNodeId(): void
    {
        $levelTypeId = $this->db->insert('org_level_types', [
            'name'  => 'Group',
            'depth' => 1,
        ]);

        $nodeA = $this->db->insert('org_nodes', [
            'level_type_id' => $levelTypeId,
            'name'          => 'Node A',
        ]);

        // Assign only Alice and Bob to Node A
        $this->db->insert('member_nodes', ['member_id' => $this->memberAlice, 'node_id' => $nodeA]);
        $this->db->insert('member_nodes', ['member_id' => $this->memberBob,   'node_id' => $nodeA]);
        // Carol is NOT in Node A

        $members = $this->service->getOptedInMembers('general', [$nodeA]);
        $emails = array_column($members, 'email');

        $this->assertContains('alice@example.com', $emails);
        $this->assertContains('bob@example.com', $emails);
        $this->assertNotContains('carol@example.com', $emails);
    }

    public function testGetOptedInMembersWithNodeFilterStillExcludesOptedOut(): void
    {
        $levelTypeId = $this->db->insert('org_level_types', [
            'name'  => 'Section',
            'depth' => 2,
        ]);

        $nodeB = $this->db->insert('org_nodes', [
            'level_type_id' => $levelTypeId,
            'name'          => 'Node B',
        ]);

        $this->db->insert('member_nodes', ['member_id' => $this->memberAlice, 'node_id' => $nodeB]);
        $this->db->insert('member_nodes', ['member_id' => $this->memberCarol, 'node_id' => $nodeB]);

        $this->service->setPreference($this->memberAlice, 'general', false);

        $members = $this->service->getOptedInMembers('general', [$nodeB]);
        $emails = array_column($members, 'email');

        $this->assertNotContains('alice@example.com', $emails);
        $this->assertContains('carol@example.com', $emails);
    }

    public function testGetOptedInMembersReturnsExpectedFields(): void
    {
        $members = $this->service->getOptedInMembers('general');

        $this->assertNotEmpty($members);
        $first = $members[0];
        $this->assertArrayHasKey('member_id', $first);
        $this->assertArrayHasKey('email', $first);
        $this->assertArrayHasKey('first_name', $first);
        $this->assertArrayHasKey('surname', $first);
    }

    public function testGetOptedInMembersOrderedBySurnameThenFirstName(): void
    {
        $members = $this->service->getOptedInMembers('general');

        $surnames = array_column($members, 'surname');
        $sorted = $surnames;
        sort($sorted);

        $this->assertSame($sorted, $surnames);
    }

    // ── Cascade delete ────────────────────────────────────────────────

    public function testPreferenceRowsCascadeWhenMemberDeleted(): void
    {
        $this->service->setPreference($this->memberAlice, 'general', false);
        $this->service->setPreference($this->memberAlice, 'newsletter', false);

        $this->db->query(
            "DELETE FROM members WHERE id = :id",
            ['id' => $this->memberAlice]
        );

        $rows = $this->db->fetchAll(
            "SELECT * FROM member_email_preferences WHERE member_id = :mid",
            ['mid' => $this->memberAlice]
        );

        $this->assertSame([], $rows);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function insertMember(
        string $email,
        string $firstName,
        string $surname,
        string $status = 'active'
    ): int {
        static $counter = 0;
        $counter++;

        return $this->db->insert('members', [
            'membership_number' => sprintf('SK-TEST-%04d', $counter),
            'first_name'        => $firstName,
            'surname'           => $surname,
            'email'             => $email,
            'status'            => $status,
        ]);
    }
}

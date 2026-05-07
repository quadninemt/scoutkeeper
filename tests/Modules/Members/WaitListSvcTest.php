<?php

declare(strict_types=1);

namespace Tests\Modules\Members;

use PHPUnit\Framework\TestCase;
use App\Core\Database;
use App\Modules\Members\Services\WaitingListService;
use App\Modules\Members\Services\RegistrationService;

/**
 * Tests for WaitingListService.
 *
 * Covers add, list, reorder, status transitions, conversion to
 * member, deletion, and counts.
 */
class WaitListSvcTest extends TestCase
{
    private Database $db;
    private WaitingListService $service;

    protected function setUp(): void
    {
        try {
            $this->db = new Database(TEST_CONFIG['db']);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        // Drop in dependency order
        $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
        $this->db->query("DROP TABLE IF EXISTS `registration_invitations`");
        $this->db->query("DROP TABLE IF EXISTS `waiting_list`");
        $this->db->query("DROP TABLE IF EXISTS `member_attachments`");
        $this->db->query("DROP TABLE IF EXISTS `member_timeline`");
        $this->db->query("DROP TABLE IF EXISTS `medical_access_log`");
        $this->db->query("DROP TABLE IF EXISTS `member_pending_changes`");
        $this->db->query("DROP TABLE IF EXISTS `member_nodes`");
        $this->db->query("DROP TABLE IF EXISTS `members`");
        $this->db->query("DROP TABLE IF EXISTS `custom_field_definitions`");
        $this->db->query("DROP TABLE IF EXISTS `org_closure`");
        $this->db->query("DROP TABLE IF EXISTS `org_teams`");
        $this->db->query("DROP TABLE IF EXISTS `org_nodes`");
        $this->db->query("DROP TABLE IF EXISTS `org_level_types`");
        $this->db->query("DROP TABLE IF EXISTS `role_assignment_scopes`");
        $this->db->query("DROP TABLE IF EXISTS `role_assignments`");
        $this->db->query("DROP TABLE IF EXISTS `roles`");
        $this->db->query("DROP TABLE IF EXISTS `password_resets`");
        $this->db->query("DROP TABLE IF EXISTS `user_sessions`");
        $this->db->query("DROP TABLE IF EXISTS `users`");
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");

        // Create users
        $this->db->query("
            CREATE TABLE `users` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `email` VARCHAR(255) NOT NULL UNIQUE,
                `password_hash` VARCHAR(255) NOT NULL DEFAULT '',
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `is_super_admin` TINYINT(1) NOT NULL DEFAULT 0,
                `password_changed_at` DATETIME NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create org_level_types
        $this->db->query("
            CREATE TABLE `org_level_types` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL,
                `depth` INT UNSIGNED NOT NULL DEFAULT 0,
                `is_leaf` TINYINT(1) NOT NULL DEFAULT 0,
                `sort_order` INT UNSIGNED NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create org_nodes
        $this->db->query("
            CREATE TABLE `org_nodes` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(200) NOT NULL,
                `short_name` VARCHAR(50) NULL,
                `parent_id` INT UNSIGNED NULL,
                `level_type_id` INT UNSIGNED NULL,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT `fk_node_parent` FOREIGN KEY (`parent_id`) REFERENCES `org_nodes` (`id`) ON DELETE SET NULL,
                CONSTRAINT `fk_node_level` FOREIGN KEY (`level_type_id`) REFERENCES `org_level_types` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create members
        $this->db->query("
            CREATE TABLE `members` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `membership_number` VARCHAR(20) NOT NULL,
                `first_name` VARCHAR(100) NOT NULL,
                `surname` VARCHAR(100) NOT NULL,
                `email` VARCHAR(255) NULL,
                `phone` VARCHAR(50) NULL,
                `dob` DATE NULL,
                `gender` ENUM('male','female','other','prefer_not_to_say') NULL,
                `address_line1` VARCHAR(200) NULL,
                `address_line2` VARCHAR(200) NULL,
                `city` VARCHAR(100) NULL,
                `postcode` VARCHAR(20) NULL,
                `country` VARCHAR(100) NULL,
                `status` ENUM('active','pending','suspended','inactive','left') NOT NULL DEFAULT 'pending',
                `status_reason` TEXT NULL,
                `joined_date` DATE NULL,
                `user_id` INT UNSIGNED NULL,
                `gdpr_consent` TINYINT(1) NOT NULL DEFAULT 0,
                `member_custom_data` JSON NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FULLTEXT KEY `ft_member_search` (`first_name`, `surname`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create member_nodes
        $this->db->query("
            CREATE TABLE `member_nodes` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `member_id` INT UNSIGNED NOT NULL,
                `node_id` INT UNSIGNED NOT NULL,
                `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
                CONSTRAINT `fk_mn_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_mn_node` FOREIGN KEY (`node_id`) REFERENCES `org_nodes` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create waiting_list
        $this->db->query("
            CREATE TABLE `waiting_list` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `position` INT UNSIGNED NOT NULL DEFAULT 0,
                `parent_name` VARCHAR(200) NOT NULL,
                `parent_email` VARCHAR(255) NOT NULL,
                `child_name` VARCHAR(200) NOT NULL,
                `child_dob` DATE NULL,
                `preferred_node_id` INT UNSIGNED NULL,
                `notes` TEXT NULL,
                `status` ENUM('waiting','contacted','converted','withdrawn') NOT NULL DEFAULT 'waiting',
                `converted_member_id` INT UNSIGNED NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT `fk_wl_node` FOREIGN KEY (`preferred_node_id`) REFERENCES `org_nodes` (`id`) ON DELETE SET NULL,
                CONSTRAINT `fk_wl_member` FOREIGN KEY (`converted_member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Seed
        $this->db->insert('org_level_types', ['name' => 'Group', 'depth' => 0, 'is_leaf' => 1, 'sort_order' => 0]);
        $this->db->insert('org_nodes', ['name' => 'Test Group', 'level_type_id' => 1]);

        $this->service = new WaitingListService($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
        $this->db->query("DROP TABLE IF EXISTS `waiting_list`");
        $this->db->query("DROP TABLE IF EXISTS `member_nodes`");
        $this->db->query("DROP TABLE IF EXISTS `members`");
        $this->db->query("DROP TABLE IF EXISTS `org_nodes`");
        $this->db->query("DROP TABLE IF EXISTS `org_level_types`");
        $this->db->query("DROP TABLE IF EXISTS `users`");
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");
    }

    // ── Add Entry ────────────────────────────────────────────────────

    public function testAddEntryCreatesRecord(): void
    {
        $id = $this->service->addEntry([
            'parent_name' => 'Maria Borg',
            'parent_email' => 'maria@test.local',
            'child_name' => 'Luke Borg',
        ]);

        $this->assertGreaterThan(0, $id);

        $entry = $this->service->getById($id);
        $this->assertSame('Maria Borg', $entry['parent_name']);
        $this->assertSame('maria@test.local', $entry['parent_email']);
        $this->assertSame('Luke Borg', $entry['child_name']);
        $this->assertSame('waiting', $entry['status']);
    }

    public function testAddEntryWithNodePreference(): void
    {
        $id = $this->service->addEntry([
            'parent_name' => 'Maria Borg',
            'parent_email' => 'maria@test.local',
            'child_name' => 'Luke Borg',
        ], 1);

        $entry = $this->service->getById($id);
        $this->assertEquals(1, $entry['preferred_node_id']);
    }

    public function testAddEntryAutoIncrements_position(): void
    {
        $id1 = $this->service->addEntry([
            'parent_name' => 'A Parent', 'parent_email' => 'a@test.local', 'child_name' => 'A Child',
        ]);
        $id2 = $this->service->addEntry([
            'parent_name' => 'B Parent', 'parent_email' => 'b@test.local', 'child_name' => 'B Child',
        ]);

        $e1 = $this->service->getById($id1);
        $e2 = $this->service->getById($id2);
        $this->assertSame(1, (int) $e1['position']);
        $this->assertSame(2, (int) $e2['position']);
    }

    public function testAddEntryRequiresFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->addEntry(['parent_name' => 'Test']);
    }

    public function testAddEntryValidatesEmail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->addEntry([
            'parent_name' => 'Test', 'parent_email' => 'bad-email', 'child_name' => 'Child',
        ]);
    }

    // ── Get List ─────────────────────────────────────────────────────

    public function testGetListReturnsAllEntries(): void
    {
        $this->service->addEntry([
            'parent_name' => 'A', 'parent_email' => 'a@t.com', 'child_name' => 'C1',
        ]);
        $this->service->addEntry([
            'parent_name' => 'B', 'parent_email' => 'b@t.com', 'child_name' => 'C2',
        ]);

        $list = $this->service->getList();
        $this->assertCount(2, $list);
    }

    public function testGetListFiltersByStatus(): void
    {
        $id = $this->service->addEntry([
            'parent_name' => 'A', 'parent_email' => 'a@t.com', 'child_name' => 'C1',
        ]);
        $this->service->addEntry([
            'parent_name' => 'B', 'parent_email' => 'b@t.com', 'child_name' => 'C2',
        ]);
        $this->service->updateStatus($id, 'contacted');

        $waiting = $this->service->getList('waiting');
        $contacted = $this->service->getList('contacted');

        $this->assertCount(1, $waiting);
        $this->assertCount(1, $contacted);
    }

    public function testGetListOrdersByPosition(): void
    {
        $this->service->addEntry([
            'parent_name' => 'A', 'parent_email' => 'a@t.com', 'child_name' => 'First',
        ]);
        $this->service->addEntry([
            'parent_name' => 'B', 'parent_email' => 'b@t.com', 'child_name' => 'Second',
        ]);

        $list = $this->service->getList();
        $this->assertSame('First', $list[0]['child_name']);
        $this->assertSame('Second', $list[1]['child_name']);
    }

    // ── Reorder ──────────────────────────────────────────────────────

    public function testReorderUpdatesPositions(): void
    {
        $id1 = $this->service->addEntry([
            'parent_name' => 'A', 'parent_email' => 'a@t.com', 'child_name' => 'First',
        ]);
        $id2 = $this->service->addEntry([
            'parent_name' => 'B', 'parent_email' => 'b@t.com', 'child_name' => 'Second',
        ]);

        // Swap order
        $this->service->reorder([$id2, $id1]);

        $list = $this->service->getList();
        $this->assertSame('Second', $list[0]['child_name']);
        $this->assertSame('First', $list[1]['child_name']);
    }

    // ── Status Transitions ───────────────────────────────────────────

    public function testUpdateStatusWaitingToContacted(): void
    {
        $id = $this->service->addEntry([
            'parent_name' => 'A', 'parent_email' => 'a@t.com', 'child_name' => 'C',
        ]);

        $this->service->updateStatus($id, 'contacted');

        $entry = $this->service->getById($id);
        $this->assertSame('contacted', $entry['status']);
    }

    public function testUpdateStatusRejectsInvalidTransition(): void
    {
        $id = $this->service->addEntry([
            'parent_name' => 'A', 'parent_email' => 'a@t.com', 'child_name' => 'C',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot transition');
        $this->service->updateStatus($id, 'converted'); // waiting -> converted not allowed
    }

    public function testWithdrawnCanBeReinstated(): void
    {
        $id = $this->service->addEntry([
            'parent_name' => 'A', 'parent_email' => 'a@t.com', 'child_name' => 'C',
        ]);

        $this->service->updateStatus($id, 'withdrawn');
        $this->service->updateStatus($id, 'waiting');

        $entry = $this->service->getById($id);
        $this->assertSame('waiting', $entry['status']);
    }

    public function testConvertedCannotTransition(): void
    {
        $id = $this->service->addEntry([
            'parent_name' => 'A', 'parent_email' => 'a@t.com', 'child_name' => 'C',
        ]);
        $this->service->updateStatus($id, 'contacted');

        // Manually set to converted for testing
        $this->db->query("UPDATE `waiting_list` SET `status` = 'converted' WHERE `id` = ?", [$id]);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->updateStatus($id, 'waiting');
    }

    // ── Notes ────────────────────────────────────────────────────────

    public function testUpdateNotes(): void
    {
        $id = $this->service->addEntry([
            'parent_name' => 'A', 'parent_email' => 'a@t.com', 'child_name' => 'C',
        ]);

        $this->service->updateNotes($id, 'Very interested');

        $entry = $this->service->getById($id);
        $this->assertSame('Very interested', $entry['notes']);
    }

    // ── Convert to Registration ──────────────────────────────────────

    public function testConvertToRegistrationCreatesMember(): void
    {
        $id = $this->service->addEntry([
            'parent_name' => 'Maria Borg',
            'parent_email' => 'maria@test.local',
            'child_name' => 'Luke Borg',
            'child_dob' => '2015-06-15',
        ], 1);

        $regService = new RegistrationService($this->db);
        $memberId = $this->service->convertToRegistration($id, $regService);

        $this->assertGreaterThan(0, $memberId);

        // Entry should be converted
        $entry = $this->service->getById($id);
        $this->assertSame('converted', $entry['status']);
        $this->assertEquals($memberId, $entry['converted_member_id']);

        // Member should exist
        $member = $this->db->fetchOne("SELECT * FROM `members` WHERE `id` = ?", [$memberId]);
        $this->assertSame('Luke', $member['first_name']);
        $this->assertSame('Borg', $member['surname']);
        $this->assertSame('pending', $member['status']);
    }

    public function testConvertAlreadyConvertedThrows(): void
    {
        $id = $this->service->addEntry([
            'parent_name' => 'A', 'parent_email' => 'a@t.com', 'child_name' => 'C Child',
        ], 1);
        $this->service->updateStatus($id, 'contacted');
        $this->db->query("UPDATE `waiting_list` SET `status` = 'converted' WHERE `id` = ?", [$id]);

        $regService = new RegistrationService($this->db);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already been converted');
        $this->service->convertToRegistration($id, $regService);
    }

    public function testConvertWithdrawnThrows(): void
    {
        $id = $this->service->addEntry([
            'parent_name' => 'A', 'parent_email' => 'a@t.com', 'child_name' => 'C Child',
        ], 1);
        $this->service->updateStatus($id, 'withdrawn');

        $regService = new RegistrationService($this->db);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('withdrawn');
        $this->service->convertToRegistration($id, $regService);
    }

    // ── Delete ───────────────────────────────────────────────────────

    public function testDeleteWaitingEntry(): void
    {
        $id = $this->service->addEntry([
            'parent_name' => 'A', 'parent_email' => 'a@t.com', 'child_name' => 'C',
        ]);

        $this->service->deleteEntry($id);

        $this->assertNull($this->service->getById($id));
    }

    public function testDeleteWithdrawnEntry(): void
    {
        $id = $this->service->addEntry([
            'parent_name' => 'A', 'parent_email' => 'a@t.com', 'child_name' => 'C',
        ]);
        $this->service->updateStatus($id, 'withdrawn');

        $this->service->deleteEntry($id);

        $this->assertNull($this->service->getById($id));
    }

    public function testCannotDeleteContactedEntry(): void
    {
        $id = $this->service->addEntry([
            'parent_name' => 'A', 'parent_email' => 'a@t.com', 'child_name' => 'C',
        ]);
        $this->service->updateStatus($id, 'contacted');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->deleteEntry($id);
    }

    // ── Counts ───────────────────────────────────────────────────────

    public function testGetCountsByStatus(): void
    {
        $id1 = $this->service->addEntry([
            'parent_name' => 'A', 'parent_email' => 'a@t.com', 'child_name' => 'C1',
        ]);
        $this->service->addEntry([
            'parent_name' => 'B', 'parent_email' => 'b@t.com', 'child_name' => 'C2',
        ]);
        $this->service->updateStatus($id1, 'contacted');

        $counts = $this->service->getCountsByStatus();

        $this->assertSame(1, $counts['waiting']);
        $this->assertSame(1, $counts['contacted']);
        $this->assertSame(0, $counts['converted']);
        $this->assertSame(0, $counts['withdrawn']);
    }
}

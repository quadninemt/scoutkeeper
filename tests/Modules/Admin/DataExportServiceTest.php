<?php

declare(strict_types=1);

namespace Tests\Modules\Admin;

use App\Core\Database;
use App\Modules\Admin\Services\DataExportService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DataExportService.
 *
 * Covers the members CSV export (header, ordering, node scoping, node
 * name aggregation), the members XML export (well-formedness, escaping),
 * the per-member GDPR export (sections, medical notes exclusion, missing
 * member), and the settings JSON export.
 *
 * Regression note: exportMyDataCsv() previously queried tables that don't
 * exist in any migration (custom_fields/custom_field_data/timeline_entries
 * and a member_id-shaped role_assignments) and threw on first use. These
 * tests run against the real schema (custom_field_definitions + the
 * members.member_custom_data JSON column, member_timeline, and the
 * user_id/context role_assignments shape).
 */
class DataExportServiceTest extends TestCase
{
    private Database $db;
    private DataExportService $service;
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
            CREATE TABLE `members` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NULL,
                `membership_number` VARCHAR(50) NOT NULL UNIQUE,
                `first_name` VARCHAR(100) NOT NULL,
                `surname` VARCHAR(100) NOT NULL,
                `dob` DATE NULL,
                `gender` ENUM('male', 'female', 'other', 'prefer_not_to_say') NULL,
                `email` VARCHAR(255) NULL,
                `phone` VARCHAR(50) NULL,
                `medical_notes` TEXT NULL COMMENT 'AES-256-GCM encrypted',
                `member_custom_data` JSON NULL,
                `status` ENUM('active', 'pending', 'suspended', 'inactive', 'left') NOT NULL DEFAULT 'pending',
                `joined_date` DATE NULL,
                `left_date` DATE NULL,
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
            CREATE TABLE `settings` (
                `key` VARCHAR(100) NOT NULL PRIMARY KEY,
                `value` TEXT NULL,
                `group` VARCHAR(50) NOT NULL DEFAULT 'general',
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Real-schema tables used by exportMyDataCsv()
        $this->db->query("
            CREATE TABLE `custom_field_definitions` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `field_key` VARCHAR(100) NOT NULL,
                `label` VARCHAR(200) NOT NULL,
                `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
                UNIQUE KEY `uq_field_key` (`field_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE `member_timeline` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `member_id` INT UNSIGNED NOT NULL,
                `field_key` VARCHAR(100) NOT NULL,
                `value` VARCHAR(500) NOT NULL,
                `effective_date` DATE NOT NULL,
                `recorded_by` INT UNSIGNED NULL,
                `notes` TEXT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE `roles` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL UNIQUE,
                `description` VARCHAR(500) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE `org_teams` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(200) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE `role_assignments` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NOT NULL,
                `role_id` INT UNSIGNED NOT NULL,
                `context_type` ENUM('node', 'team', 'global') NOT NULL DEFAULT 'global',
                `context_id` INT UNSIGNED NULL,
                `start_date` DATE NOT NULL,
                `end_date` DATE NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->levelTypeId = $this->db->insert('org_level_types', ['name' => 'Group']);

        $this->service = new DataExportService($this->db);
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
                'role_assignments', 'org_teams', 'roles', 'member_timeline',
                'custom_field_definitions', 'settings', 'member_nodes', 'members',
                'org_nodes', 'org_level_types', 'users',
            ] as $table
        ) {
            $this->db->query("DROP TABLE IF EXISTS `$table`");
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    // ── Fixture helpers ──────────────────────────────────────────────────

    private function createNode(string $name): int
    {
        return $this->db->insert('org_nodes', [
            'level_type_id' => $this->levelTypeId,
            'name' => $name,
        ]);
    }

    private function createMember(array $overrides = []): int
    {
        static $seq = 0;
        $seq++;

        return $this->db->insert('members', array_merge([
            'membership_number' => "M{$seq}",
            'first_name' => "First{$seq}",
            'surname' => "Surname{$seq}",
            'email' => "member{$seq}@example.com",
            'status' => 'active',
        ], $overrides));
    }

    private function linkMemberToNode(int $memberId, int $nodeId, bool $primary = false): void
    {
        $this->db->insert('member_nodes', [
            'member_id' => $memberId,
            'node_id' => $nodeId,
            'is_primary' => $primary ? 1 : 0,
        ]);
    }

    private function insertSetting(string $key, ?string $value, string $group): void
    {
        $this->db->query(
            "INSERT INTO `settings` (`key`, `value`, `group`) VALUES (?, ?, ?)",
            [$key, $value, $group]
        );
    }

    // ── exportMembersCsv() ───────────────────────────────────────────────

    public function testExportMembersCsvIncludesHeaderRow(): void
    {
        $csv = $this->service->exportMembersCsv();

        $lines = explode("\n", trim($csv));
        $this->assertSame(
            [
                'membership_number', 'first_name', 'surname', 'email', 'phone',
                'dob', 'gender', 'status', 'joined_date', 'node_names',
            ],
            str_getcsv($lines[0], ',', '"', '\\')
        );
    }

    public function testExportMembersCsvWithNoMembersReturnsOnlyHeader(): void
    {
        $csv = $this->service->exportMembersCsv();

        $this->assertCount(1, explode("\n", trim($csv)));
    }

    public function testExportMembersCsvIncludesMemberRow(): void
    {
        $this->createMember([
            'membership_number' => 'SC-001',
            'first_name' => 'Alice',
            'surname' => 'Zammit',
            'phone' => '21234567',
            'dob' => '2010-05-04',
            'gender' => 'female',
            'joined_date' => '2020-09-01',
        ]);

        $csv = $this->service->exportMembersCsv();

        $lines = explode("\n", trim($csv));
        $this->assertCount(2, $lines);

        $row = str_getcsv($lines[1], ',', '"', '\\');
        $this->assertSame('SC-001', $row[0]);
        $this->assertSame('Alice', $row[1]);
        $this->assertSame('Zammit', $row[2]);
        $this->assertSame('21234567', $row[4]);
        $this->assertSame('2010-05-04', $row[5]);
        $this->assertSame('female', $row[6]);
        $this->assertSame('active', $row[7]);
        $this->assertSame('2020-09-01', $row[8]);
    }

    public function testExportMembersCsvOrdersBySurname(): void
    {
        $this->createMember(['surname' => 'Zerafa', 'first_name' => 'Zack']);
        $this->createMember(['surname' => 'Abela', 'first_name' => 'Anna']);

        $csv = $this->service->exportMembersCsv();

        $lines = explode("\n", trim($csv));
        $this->assertSame('Abela', str_getcsv($lines[1], ',', '"', '\\')[2]);
        $this->assertSame('Zerafa', str_getcsv($lines[2], ',', '"', '\\')[2]);
    }

    public function testExportMembersCsvFiltersByNode(): void
    {
        $nodeA = $this->createNode('1st Group');
        $nodeB = $this->createNode('2nd Group');

        $inNode = $this->createMember(['surname' => 'Included']);
        $this->linkMemberToNode($inNode, $nodeA);

        $otherNode = $this->createMember(['surname' => 'OtherNode']);
        $this->linkMemberToNode($otherNode, $nodeB);

        $this->createMember(['surname' => 'NoNode']);

        $csv = $this->service->exportMembersCsv([$nodeA]);

        $this->assertStringContainsString('Included', $csv);
        $this->assertStringNotContainsString('OtherNode', $csv);
        $this->assertStringNotContainsString('NoNode', $csv);
    }

    public function testExportMembersCsvConcatenatesNodeNamesPrimaryFirst(): void
    {
        $primaryNode = $this->createNode('Primary Group');
        $secondaryNode = $this->createNode('Secondary Group');

        $memberId = $this->createMember();
        $this->linkMemberToNode($memberId, $secondaryNode, false);
        $this->linkMemberToNode($memberId, $primaryNode, true);

        $csv = $this->service->exportMembersCsv();

        $lines = explode("\n", trim($csv));
        $this->assertSame('Primary Group, Secondary Group', str_getcsv($lines[1], ',', '"', '\\')[9]);
    }

    // ── exportMembersXml() ───────────────────────────────────────────────

    public function testExportMembersXmlProducesWellFormedXmlWithMemberData(): void
    {
        $this->createMember([
            'membership_number' => 'SC-100',
            'first_name' => 'Bob',
            'surname' => 'Micallef',
        ]);

        $xml = $this->service->exportMembersXml();

        $parsed = simplexml_load_string($xml);
        $this->assertNotFalse($parsed);
        $this->assertCount(1, $parsed->member);
        $this->assertSame('SC-100', (string) $parsed->member[0]->membership_number);
        $this->assertSame('Bob', (string) $parsed->member[0]->first_name);
        $this->assertSame('Micallef', (string) $parsed->member[0]->surname);
    }

    public function testExportMembersXmlWithNoMembersHasNoMemberElements(): void
    {
        $xml = $this->service->exportMembersXml();

        $parsed = simplexml_load_string($xml);
        $this->assertNotFalse($parsed);
        $this->assertCount(0, $parsed->member);
    }

    public function testExportMembersXmlEscapesSpecialCharacters(): void
    {
        $this->createMember(['surname' => "O'Brien & Sons"]);

        $xml = $this->service->exportMembersXml();

        $parsed = simplexml_load_string($xml);
        $this->assertNotFalse($parsed);
        $this->assertSame("O'Brien & Sons", (string) $parsed->member[0]->surname);
    }

    // ── exportMyDataCsv() ────────────────────────────────────────────────

    public function testExportMyDataCsvThrowsForMissingMember(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/i');

        $this->service->exportMyDataCsv(999999);
    }

    public function testExportMyDataCsvIncludesProfileAndExcludesMedicalNotes(): void
    {
        $memberId = $this->createMember([
            'first_name' => 'Carla',
            'surname' => 'Vella',
            'medical_notes' => 'ENCRYPTED-SECRET-BLOB',
        ]);

        $csv = $this->service->exportMyDataCsv($memberId);

        $this->assertStringContainsString('--- MEMBER PROFILE ---', $csv);
        $this->assertStringContainsString('Carla', $csv);
        $this->assertStringContainsString('Vella', $csv);
        $this->assertStringNotContainsString('medical_notes', $csv);
        $this->assertStringNotContainsString('ENCRYPTED-SECRET-BLOB', $csv);
    }

    public function testExportMyDataCsvOmitsEmptySections(): void
    {
        $memberId = $this->createMember();

        $csv = $this->service->exportMyDataCsv($memberId);

        $this->assertStringNotContainsString('--- CUSTOM FIELDS ---', $csv);
        $this->assertStringNotContainsString('--- TIMELINE ---', $csv);
        $this->assertStringNotContainsString('--- ROLE ASSIGNMENTS ---', $csv);
    }

    public function testExportMyDataCsvIncludesCustomFieldsTimelineAndRoles(): void
    {
        $userId = $this->db->insert('users', ['email' => 'pl@example.com']);
        $memberId = $this->createMember([
            'user_id' => $userId,
            'member_custom_data' => json_encode(['allergy_info' => 'Peanuts']),
        ]);

        $this->db->insert('custom_field_definitions', [
            'field_key' => 'allergy_info',
            'label' => 'Allergy Info',
            'sort_order' => 1,
        ]);

        $this->db->insert('member_timeline', [
            'member_id' => $memberId,
            'field_key' => 'rank',
            'value' => 'Joined the troop',
            'effective_date' => '2024-01-01',
        ]);

        $roleId = $this->db->insert('roles', ['name' => 'Patrol Leader']);
        $nodeId = $this->createNode('1st Test Group');
        $this->db->insert('role_assignments', [
            'user_id' => $userId,
            'role_id' => $roleId,
            'context_type' => 'node',
            'context_id' => $nodeId,
            'start_date' => '2024-01-01',
        ]);

        $csv = $this->service->exportMyDataCsv($memberId);

        $this->assertStringContainsString('--- CUSTOM FIELDS ---', $csv);
        $this->assertStringContainsString('Allergy Info', $csv);
        $this->assertStringContainsString('Peanuts', $csv);

        $this->assertStringContainsString('--- TIMELINE ---', $csv);
        $this->assertStringContainsString('Joined the troop', $csv);

        $this->assertStringContainsString('--- ROLE ASSIGNMENTS ---', $csv);
        $this->assertStringContainsString('Patrol Leader', $csv);
        $this->assertStringContainsString('1st Test Group', $csv);
    }

    public function testExportMyDataCsvUsesFieldKeyWhenDefinitionMissing(): void
    {
        $memberId = $this->createMember([
            'member_custom_data' => json_encode(['orphan_key' => 'Some value']),
        ]);

        $csv = $this->service->exportMyDataCsv($memberId);

        $this->assertStringContainsString('--- CUSTOM FIELDS ---', $csv);
        $this->assertStringContainsString('orphan_key', $csv);
        $this->assertStringContainsString('Some value', $csv);
    }

    public function testExportMyDataCsvExcludesOtherMembersData(): void
    {
        $memberId = $this->createMember();
        $otherId = $this->createMember(['first_name' => 'Otto', 'surname' => 'Otherman']);

        $this->db->insert('member_timeline', [
            'member_id' => $otherId,
            'field_key' => 'note',
            'value' => 'Private note about Otto',
            'effective_date' => '2024-01-01',
        ]);

        $csv = $this->service->exportMyDataCsv($memberId);

        $this->assertStringNotContainsString('Otherman', $csv);
        $this->assertStringNotContainsString('Private note about Otto', $csv);
    }

    // ── exportSettingsJson() ─────────────────────────────────────────────

    public function testExportSettingsJsonGroupsSettingsByGroup(): void
    {
        $this->insertSetting('org_name', 'ScoutKeeper', 'general');
        $this->insertSetting('timezone', 'Europe/Malta', 'general');
        $this->insertSetting('session_timeout', '3600', 'security');

        $json = $this->service->exportSettingsJson();
        $decoded = json_decode($json, true);

        $this->assertSame(
            [
                'general' => ['org_name' => 'ScoutKeeper', 'timezone' => 'Europe/Malta'],
                'security' => ['session_timeout' => '3600'],
            ],
            $decoded
        );
    }

    public function testExportSettingsJsonPreservesUnicodeValues(): void
    {
        $this->insertSetting('org_name', 'Skäutkeeper Ħamrun', 'general');

        $json = $this->service->exportSettingsJson();

        $this->assertStringContainsString('Skäutkeeper Ħamrun', $json);
    }

    public function testExportSettingsJsonWithNoSettingsReturnsEmptyJson(): void
    {
        $decoded = json_decode($this->service->exportSettingsJson(), true);

        $this->assertSame([], $decoded);
    }
}

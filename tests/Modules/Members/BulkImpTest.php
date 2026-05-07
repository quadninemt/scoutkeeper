<?php

declare(strict_types=1);

namespace Tests\Modules\Members;

use PHPUnit\Framework\TestCase;
use App\Core\Database;
use App\Modules\Members\Services\BulkImportService;

/**
 * Tests for BulkImportService.
 *
 * Covers CSV template generation, upload parsing with validation,
 * and bulk import execution.
 */
class BulkImpTest extends TestCase
{
    private Database $db;
    private BulkImportService $service;

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
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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
                `joined_date` DATE NULL,
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

        // Create custom_field_definitions
        $this->db->query("
            CREATE TABLE `custom_field_definitions` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `field_key` VARCHAR(50) NOT NULL UNIQUE,
                `field_type` ENUM('short_text','long_text','number','dropdown','date') NOT NULL,
                `label` VARCHAR(200) NOT NULL,
                `description` TEXT NULL,
                `is_required` TINYINT(1) NOT NULL DEFAULT 0,
                `display_group` VARCHAR(50) NOT NULL DEFAULT 'general',
                `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `validation_rules` JSON NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Seed
        $this->db->insert('org_level_types', ['name' => 'Group', 'depth' => 0, 'is_leaf' => 1, 'sort_order' => 0]);
        $this->db->insert('org_nodes', ['name' => 'Test Group', 'level_type_id' => 1]);

        $this->service = new BulkImportService($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
        $this->db->query("DROP TABLE IF EXISTS `custom_field_definitions`");
        $this->db->query("DROP TABLE IF EXISTS `member_nodes`");
        $this->db->query("DROP TABLE IF EXISTS `members`");
        $this->db->query("DROP TABLE IF EXISTS `org_nodes`");
        $this->db->query("DROP TABLE IF EXISTS `org_level_types`");
        $this->db->query("DROP TABLE IF EXISTS `users`");
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");
    }

    // ── Template Generation ──────────────────────────────────────────

    public function testGenerateTemplateIncludesCoreColumns(): void
    {
        $csv = $this->service->generateTemplate(1);
        $lines = explode("\n", trim($csv));
        $headers = str_getcsv($lines[0]);

        $this->assertContains('first_name', $headers);
        $this->assertContains('surname', $headers);
        $this->assertContains('email', $headers);
        $this->assertContains('phone', $headers);
        $this->assertContains('dob', $headers);
        $this->assertContains('gender', $headers);
        $this->assertContains('country', $headers);
    }

    public function testGenerateTemplateIncludesCustomFields(): void
    {
        $this->db->insert('custom_field_definitions', [
            'field_key' => 'shirt_size',
            'field_type' => 'short_text',
            'label' => 'Shirt Size',
            'sort_order' => 1,
            'is_active' => 1,
        ]);

        $csv = $this->service->generateTemplate(1);
        $lines = explode("\n", trim($csv));
        $headers = str_getcsv($lines[0]);

        $this->assertContains('custom_shirt_size', $headers);
    }

    public function testGenerateTemplateIncludesExampleRow(): void
    {
        $csv = $this->service->generateTemplate(1);
        $lines = explode("\n", trim($csv));

        $this->assertGreaterThanOrEqual(2, count($lines));
        $example = str_getcsv($lines[1]);
        $this->assertSame('John', $example[0]);
        $this->assertSame('Doe', $example[1]);
    }

    // ── Parse Upload ─────────────────────────────────────────────────

    public function testParseUploadWithValidRows(): void
    {
        $csv = "first_name,surname,email\nAlice,Smith,alice@test.local\nBob,Jones,bob@test.local\n";
        $file = $this->writeTempCsv($csv);

        $result = $this->service->parseUpload(1, $file);

        $this->assertCount(2, $result['valid']);
        $this->assertEmpty($result['errors']);

        unlink($file);
    }

    public function testParseUploadDetectsMissingRequiredFields(): void
    {
        $csv = "first_name,surname,email\n,Smith,missing@test.local\nBob,,bob@test.local\n";
        $file = $this->writeTempCsv($csv);

        $result = $this->service->parseUpload(1, $file);

        $this->assertEmpty($result['valid']);
        $this->assertCount(2, $result['errors']);

        unlink($file);
    }

    public function testParseUploadDetectsInvalidEmail(): void
    {
        $csv = "first_name,surname,email\nAlice,Smith,not-an-email\n";
        $file = $this->writeTempCsv($csv);

        $result = $this->service->parseUpload(1, $file);

        $this->assertEmpty($result['valid']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('Invalid email', $result['errors'][0]['errors'][0]);

        unlink($file);
    }

    public function testParseUploadDetectsDuplicateEmailInDatabase(): void
    {
        $this->db->insert('members', [
            'membership_number' => 'SK-000001',
            'first_name' => 'Existing',
            'surname' => 'Member',
            'email' => 'existing@test.local',
        ]);

        $csv = "first_name,surname,email\nNew,Member,existing@test.local\n";
        $file = $this->writeTempCsv($csv);

        $result = $this->service->parseUpload(1, $file);

        $this->assertEmpty($result['valid']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('already exists', $result['errors'][0]['errors'][0]);

        unlink($file);
    }

    public function testParseUploadDetectsDuplicateEmailInCsv(): void
    {
        $csv = "first_name,surname,email\nAlice,One,dup@test.local\nBob,Two,dup@test.local\n";
        $file = $this->writeTempCsv($csv);

        $result = $this->service->parseUpload(1, $file);

        $this->assertCount(1, $result['valid']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('Duplicate email', $result['errors'][0]['errors'][0]);

        unlink($file);
    }

    public function testParseUploadDetectsInvalidDateFormat(): void
    {
        $csv = "first_name,surname,dob\nAlice,Smith,25/12/2000\n";
        $file = $this->writeTempCsv($csv);

        $result = $this->service->parseUpload(1, $file);

        $this->assertEmpty($result['valid']);
        $this->assertStringContainsString('Invalid date', $result['errors'][0]['errors'][0]);

        unlink($file);
    }

    public function testParseUploadDetectsInvalidGender(): void
    {
        $csv = "first_name,surname,gender\nAlice,Smith,unknown\n";
        $file = $this->writeTempCsv($csv);

        $result = $this->service->parseUpload(1, $file);

        $this->assertEmpty($result['valid']);
        $this->assertStringContainsString('Invalid gender', $result['errors'][0]['errors'][0]);

        unlink($file);
    }

    public function testParseUploadSkipsEmptyRows(): void
    {
        $csv = "first_name,surname,email\nAlice,Smith,alice@test.local\n,,\n\n";
        $file = $this->writeTempCsv($csv);

        $result = $this->service->parseUpload(1, $file);

        $this->assertCount(1, $result['valid']);
        $this->assertEmpty($result['errors']);

        unlink($file);
    }

    public function testParseUploadThrowsForMissingFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->parseUpload(1, '/nonexistent/file.csv');
    }

    // ── Import ───────────────────────────────────────────────────────

    public function testImportCreatesMembers(): void
    {
        $csv = "first_name,surname,email\nAlice,Smith,alice@test.local\nBob,Jones,bob@test.local\n";
        $file = $this->writeTempCsv($csv);

        $result = $this->service->parseUpload(1, $file);
        $count = $this->service->import(1, $result['valid'], 1);

        $this->assertSame(2, $count);

        $members = $this->db->fetchAll("SELECT * FROM `members` ORDER BY id");
        $this->assertCount(2, $members);
        $this->assertSame('active', $members[0]['status']);
        $this->assertSame('alice@test.local', $members[0]['email']);

        unlink($file);
    }

    public function testImportAssignsMembersToNode(): void
    {
        $csv = "first_name,surname,email\nAlice,Smith,alice@test.local\n";
        $file = $this->writeTempCsv($csv);

        $result = $this->service->parseUpload(1, $file);
        $this->service->import(1, $result['valid'], 1);

        $nodeAssignment = $this->db->fetchOne("SELECT * FROM `member_nodes`");
        $this->assertNotNull($nodeAssignment);
        $this->assertEquals(1, $nodeAssignment['node_id']);

        unlink($file);
    }

    public function testImportGeneratesMembershipNumbers(): void
    {
        $csv = "first_name,surname\nAlice,Smith\nBob,Jones\n";
        $file = $this->writeTempCsv($csv);

        $result = $this->service->parseUpload(1, $file);
        $this->service->import(1, $result['valid'], 1);

        $members = $this->db->fetchAll("SELECT membership_number FROM `members` ORDER BY id");
        $this->assertStringStartsWith('SK-', $members[0]['membership_number']);
        $this->assertStringStartsWith('SK-', $members[1]['membership_number']);

        unlink($file);
    }

    public function testImportIncludesCustomData(): void
    {
        $this->db->insert('custom_field_definitions', [
            'field_key' => 'allergies',
            'field_type' => 'short_text',
            'label' => 'Allergies',
            'sort_order' => 1,
            'is_active' => 1,
        ]);

        $csv = "first_name,surname,custom_allergies\nAlice,Smith,Peanuts\n";
        $file = $this->writeTempCsv($csv);

        $result = $this->service->parseUpload(1, $file);
        $this->service->import(1, $result['valid'], 1);

        $member = $this->db->fetchOne("SELECT member_custom_data FROM `members`");
        $customData = json_decode($member['member_custom_data'], true);
        $this->assertSame('Peanuts', $customData['allergies']);

        unlink($file);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function writeTempCsv(string $content): string
    {
        $file = tempnam(sys_get_temp_dir(), 'csv_');
        file_put_contents($file, $content);
        return $file;
    }
}

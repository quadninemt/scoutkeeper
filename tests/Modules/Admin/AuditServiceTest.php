<?php

declare(strict_types=1);

namespace Tests\Modules\Admin;

use App\Core\Database;
use App\Modules\Admin\Services\AuditService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AuditService.
 *
 * Covers log writes, sensitive value redaction, paginated retrieval,
 * filtering by entity type / user / action / date range, entity trail
 * queries, and the distinct actions / entity-types helpers.
 */
class AuditServiceTest extends TestCase
{
    private Database $db;
    private AuditService $service;

    protected function setUp(): void
    {
        try {
            $this->db = new Database(TEST_CONFIG['db']);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->query('DROP TABLE IF EXISTS `audit_log`');
        $this->db->query('DROP TABLE IF EXISTS `users`');
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');

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
            CREATE TABLE `audit_log` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NULL,
                `action` VARCHAR(100) NOT NULL,
                `entity_type` VARCHAR(100) NOT NULL,
                `entity_id` INT UNSIGNED NULL,
                `old_values` JSON NULL,
                `new_values` JSON NULL,
                `ip_address` VARCHAR(45) NULL,
                `user_agent` VARCHAR(500) NULL,
                `node_id` INT UNSIGNED NULL,
                `view_mode` VARCHAR(50) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_audit_entity` (`entity_type`, `entity_id`),
                INDEX `idx_audit_user` (`user_id`),
                INDEX `idx_audit_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query(
            "INSERT INTO `users` (`email`, `password_hash`) VALUES (?, ?)",
            ['admin@example.com', password_hash('test', PASSWORD_BCRYPT)]
        );

        $this->service = new AuditService($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
            $this->db->query('DROP TABLE IF EXISTS `audit_log`');
            $this->db->query('DROP TABLE IF EXISTS `users`');
            $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    // ── log() ────────────────────────────────────────────────────────────

    public function testLogWritesRowToDatabase(): void
    {
        $this->service->log('create', 'member', 42, null, ['first_name' => 'Alice'], 1, '127.0.0.1');

        $row = $this->db->fetchOne("SELECT * FROM `audit_log` LIMIT 1");

        $this->assertNotNull($row);
        $this->assertSame('create', $row['action']);
        $this->assertSame('member', $row['entity_type']);
        $this->assertSame(42, (int) $row['entity_id']);
        $this->assertSame(1, (int) $row['user_id']);
        $this->assertSame('127.0.0.1', $row['ip_address']);
        $this->assertNull($row['old_values']);
    }

    public function testLogStoresNewValuesAsJson(): void
    {
        $this->service->log('create', 'member', 1, null, ['first_name' => 'Bob', 'surname' => 'Smith']);

        $row = $this->db->fetchOne("SELECT `new_values` FROM `audit_log` LIMIT 1");
        $decoded = json_decode($row['new_values'], true);

        $this->assertSame('Bob', $decoded['first_name']);
        $this->assertSame('Smith', $decoded['surname']);
    }

    public function testLogStoresOldAndNewValues(): void
    {
        $old = ['status' => 'active'];
        $new = ['status' => 'suspended'];

        $this->service->log('update', 'member', 5, $old, $new);

        $row = $this->db->fetchOne("SELECT `old_values`, `new_values` FROM `audit_log` LIMIT 1");
        $oldDecoded = json_decode($row['old_values'], true);
        $newDecoded = json_decode($row['new_values'], true);

        $this->assertSame('active', $oldDecoded['status']);
        $this->assertSame('suspended', $newDecoded['status']);
    }

    public function testLogRedactsPasswordFields(): void
    {
        $this->service->log('update', 'user', 1, ['password_hash' => 'old-hash'], ['password_hash' => 'new-hash']);

        $row = $this->db->fetchOne("SELECT `old_values`, `new_values` FROM `audit_log` LIMIT 1");
        $oldDecoded = json_decode($row['old_values'], true);
        $newDecoded = json_decode($row['new_values'], true);

        $this->assertSame('[REDACTED]', $oldDecoded['password_hash']);
        $this->assertSame('[REDACTED]', $newDecoded['password_hash']);
    }

    public function testLogRedactsMedicalFields(): void
    {
        $this->service->log('update', 'member', 1, null, ['medical_notes' => 'Allergic to nuts']);

        $row = $this->db->fetchOne("SELECT `new_values` FROM `audit_log` LIMIT 1");
        $decoded = json_decode($row['new_values'], true);

        $this->assertSame('[REDACTED]', $decoded['medical_notes']);
    }

    public function testLogRedactsMfaSecretField(): void
    {
        $this->service->log('update', 'user', 1, null, ['mfa_secret' => 'TOTPSECRET123']);

        $row = $this->db->fetchOne("SELECT `new_values` FROM `audit_log` LIMIT 1");
        $decoded = json_decode($row['new_values'], true);

        $this->assertSame('[REDACTED]', $decoded['mfa_secret']);
    }

    public function testLogRedactsEncryptionField(): void
    {
        $this->service->log('update', 'settings', null, null, ['encryption_key' => 'secret']);

        $row = $this->db->fetchOne("SELECT `new_values` FROM `audit_log` LIMIT 1");
        $decoded = json_decode($row['new_values'], true);

        $this->assertSame('[REDACTED]', $decoded['encryption_key']);
    }

    public function testLogPreservesNonSensitiveFields(): void
    {
        $values = ['first_name' => 'Carol', 'email' => 'carol@example.com', 'status' => 'active'];
        $this->service->log('create', 'member', 10, null, $values);

        $row = $this->db->fetchOne("SELECT `new_values` FROM `audit_log` LIMIT 1");
        $decoded = json_decode($row['new_values'], true);

        $this->assertSame('Carol', $decoded['first_name']);
        $this->assertSame('carol@example.com', $decoded['email']);
        $this->assertSame('active', $decoded['status']);
    }

    public function testLogTruncatesUserAgentAt500Chars(): void
    {
        $longAgent = str_repeat('A', 600);
        $this->service->log('login', 'user', 1, null, null, 1, '127.0.0.1', $longAgent);

        $row = $this->db->fetchOne("SELECT `user_agent` FROM `audit_log` LIMIT 1");
        $this->assertSame(500, mb_strlen($row['user_agent']));
    }

    public function testLogAcceptsNullOptionalParams(): void
    {
        $this->service->log('delete', 'member', 99, ['status' => 'active'], null);

        $row = $this->db->fetchOne("SELECT * FROM `audit_log` LIMIT 1");
        $this->assertNull($row['user_id']);
        $this->assertNull($row['ip_address']);
        $this->assertNull($row['user_agent']);
        $this->assertNull($row['new_values']);
    }

    // ── getLog() ─────────────────────────────────────────────────────────

    public function testGetLogReturnsPaginatedResults(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->service->log('login', 'user', $i, null, null);
        }

        $result = $this->service->getLog(1, 10);

        $this->assertSame(30, $result['total']);
        $this->assertSame(1, $result['page']);
        $this->assertSame(3, $result['pages']);
        $this->assertSame(10, $result['per_page']);
        $this->assertCount(10, $result['items']);
    }

    public function testGetLogFiltersbyEntityType(): void
    {
        $this->service->log('create', 'member', 1, null, null);
        $this->service->log('create', 'role', 1, null, null);
        $this->service->log('update', 'member', 2, null, null);

        $result = $this->service->getLog(1, 25, 'member');

        $this->assertSame(2, $result['total']);
        foreach ($result['items'] as $item) {
            $this->assertSame('member', $item['entity_type']);
        }
    }

    public function testGetLogFiltersByAction(): void
    {
        $this->service->log('create', 'member', 1, null, null);
        $this->service->log('delete', 'member', 1, null, null);
        $this->service->log('create', 'role', 1, null, null);

        $result = $this->service->getLog(1, 25, null, null, 'create');

        $this->assertSame(2, $result['total']);
        foreach ($result['items'] as $item) {
            $this->assertSame('create', $item['action']);
        }
    }

    public function testGetLogFiltersByUserId(): void
    {
        $userId = (int) $this->db->fetchColumn("SELECT `id` FROM `users` LIMIT 1");

        $this->service->log('login', 'user', $userId, null, null, $userId);
        $this->service->log('login', 'user', 99, null, null, null);

        $result = $this->service->getLog(1, 25, null, $userId);

        $this->assertSame(1, $result['total']);
        $this->assertSame($userId, (int) $result['items'][0]['user_id']);
    }

    public function testGetLogFiltersByDateFrom(): void
    {
        $this->db->query(
            "INSERT INTO `audit_log` (`action`, `entity_type`, `created_at`) VALUES (?, ?, ?)",
            ['login', 'user', '2020-01-15 10:00:00']
        );
        $this->service->log('login', 'user', null, null, null);

        $result = $this->service->getLog(1, 25, null, null, null, '2024-01-01');

        // Only the recent one should be returned
        $this->assertSame(1, $result['total']);
    }

    public function testGetLogFiltersByDateTo(): void
    {
        $this->db->query(
            "INSERT INTO `audit_log` (`action`, `entity_type`, `created_at`) VALUES (?, ?, ?)",
            ['login', 'user', '2020-06-15 10:00:00']
        );
        $this->service->log('login', 'user', null, null, null);

        $result = $this->service->getLog(1, 25, null, null, null, null, '2021-01-01');

        $this->assertSame(1, $result['total']);
    }

    public function testGetLogDecodesJsonColumns(): void
    {
        $this->service->log('update', 'member', 1, ['status' => 'active'], ['status' => 'suspended']);

        $result = $this->service->getLog(1, 25);

        $item = $result['items'][0];
        $this->assertIsArray($item['old_values']);
        $this->assertIsArray($item['new_values']);
        $this->assertSame('active', $item['old_values']['status']);
        $this->assertSame('suspended', $item['new_values']['status']);
    }

    public function testGetLogReturnsNewestFirst(): void
    {
        $this->db->query(
            "INSERT INTO `audit_log` (`action`, `entity_type`, `created_at`) VALUES (?, ?, ?)",
            ['first', 'user', '2024-01-01 00:00:00']
        );
        $this->db->query(
            "INSERT INTO `audit_log` (`action`, `entity_type`, `created_at`) VALUES (?, ?, ?)",
            ['second', 'user', '2024-06-01 00:00:00']
        );

        $result = $this->service->getLog(1, 25);

        $this->assertSame('second', $result['items'][0]['action']);
        $this->assertSame('first', $result['items'][1]['action']);
    }

    public function testGetLogIncludesUserEmail(): void
    {
        $userId = (int) $this->db->fetchColumn("SELECT `id` FROM `users` LIMIT 1");
        $this->service->log('login', 'user', null, null, null, $userId);

        $result = $this->service->getLog(1, 25);

        $this->assertSame('admin@example.com', $result['items'][0]['user_email']);
    }

    public function testGetLogReturnsEmptyOnNoRows(): void
    {
        $result = $this->service->getLog(1, 25);

        $this->assertSame(0, $result['total']);
        $this->assertSame(1, $result['page']);
        $this->assertSame(1, $result['pages']);
        $this->assertSame([], $result['items']);
    }

    public function testGetLogCombinesMultipleFilters(): void
    {
        $userId = (int) $this->db->fetchColumn("SELECT `id` FROM `users` LIMIT 1");

        $this->service->log('create', 'member', 1, null, null, $userId);
        $this->service->log('delete', 'member', 2, null, null, $userId);
        $this->service->log('create', 'member', 3, null, null, null);

        $result = $this->service->getLog(1, 25, 'member', $userId, 'create');

        $this->assertSame(1, $result['total']);
        $this->assertSame('create', $result['items'][0]['action']);
        $this->assertSame($userId, (int) $result['items'][0]['user_id']);
    }

    // ── getEntityTrail() ─────────────────────────────────────────────────

    public function testGetEntityTrailReturnsAllEntriesForEntity(): void
    {
        $this->service->log('create', 'member', 7, null, ['first_name' => 'Dave']);
        $this->service->log('update', 'member', 7, ['status' => 'active'], ['status' => 'suspended']);
        $this->service->log('update', 'member', 99, null, ['status' => 'active']); // different entity

        $trail = $this->service->getEntityTrail('member', 7);

        $this->assertCount(2, $trail);
        foreach ($trail as $entry) {
            $this->assertSame('member', $entry['entity_type']);
            $this->assertSame(7, (int) $entry['entity_id']);
        }
    }

    public function testGetEntityTrailReturnsNewestFirst(): void
    {
        $this->db->query(
            "INSERT INTO `audit_log` (`action`, `entity_type`, `entity_id`, `created_at`) VALUES (?, ?, ?, ?)",
            ['create', 'member', 5, '2024-01-01 00:00:00']
        );
        $this->db->query(
            "INSERT INTO `audit_log` (`action`, `entity_type`, `entity_id`, `created_at`) VALUES (?, ?, ?, ?)",
            ['update', 'member', 5, '2024-06-01 00:00:00']
        );

        $trail = $this->service->getEntityTrail('member', 5);

        $this->assertSame('update', $trail[0]['action']);
        $this->assertSame('create', $trail[1]['action']);
    }

    public function testGetEntityTrailDecodesJsonColumns(): void
    {
        $this->service->log('update', 'member', 3, ['status' => 'pending'], ['status' => 'active']);

        $trail = $this->service->getEntityTrail('member', 3);

        $this->assertIsArray($trail[0]['old_values']);
        $this->assertIsArray($trail[0]['new_values']);
    }

    public function testGetEntityTrailReturnsEmptyForUnknownEntity(): void
    {
        $trail = $this->service->getEntityTrail('member', 999999);
        $this->assertSame([], $trail);
    }

    // ── getActions() ─────────────────────────────────────────────────────

    public function testGetActionsReturnsDistinctSortedValues(): void
    {
        $this->service->log('update', 'member', 1, null, null);
        $this->service->log('create', 'member', 2, null, null);
        $this->service->log('create', 'role', 3, null, null);
        $this->service->log('delete', 'member', 4, null, null);

        $actions = $this->service->getActions();

        $this->assertContains('create', $actions);
        $this->assertContains('update', $actions);
        $this->assertContains('delete', $actions);
        // Each value appears only once
        $this->assertSame(count(array_unique($actions)), count($actions));
        // Sorted ascending
        $sorted = $actions;
        sort($sorted);
        $this->assertSame($sorted, $actions);
    }

    public function testGetActionsReturnsEmptyArrayWhenNoLogs(): void
    {
        $this->assertSame([], $this->service->getActions());
    }

    // ── getEntityTypes() ─────────────────────────────────────────────────

    public function testGetEntityTypesReturnsDistinctSortedValues(): void
    {
        $this->service->log('create', 'member', 1, null, null);
        $this->service->log('create', 'role', 1, null, null);
        $this->service->log('update', 'member', 2, null, null);

        $types = $this->service->getEntityTypes();

        $this->assertContains('member', $types);
        $this->assertContains('role', $types);
        $this->assertCount(2, $types);
    }
}

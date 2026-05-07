<?php

declare(strict_types=1);

namespace Tests\Modules\Admin;

use App\Core\Database;
use App\Modules\Admin\Services\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SettingsService.
 *
 * Covers get/set upsert semantics, boolean/array encoding, getGroup,
 * getAll, setMultiple, delete, and the default-value fallback.
 */
class SettingsServiceTest extends TestCase
{
    private Database $db;
    private SettingsService $service;

    protected function setUp(): void
    {
        try {
            $this->db = new Database(TEST_CONFIG['db']);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        $this->db->query('DROP TABLE IF EXISTS `settings`');

        $this->db->query("
            CREATE TABLE `settings` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `key` VARCHAR(100) NOT NULL UNIQUE,
                `value` TEXT NULL,
                `group` VARCHAR(50) NOT NULL DEFAULT 'general',
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->service = new SettingsService($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->query('DROP TABLE IF EXISTS `settings`');
        }
    }

    // ── get() ────────────────────────────────────────────────────────────

    public function testGetReturnsNullForMissingKeyByDefault(): void
    {
        $this->assertNull($this->service->get('nonexistent'));
    }

    public function testGetReturnsProvidedDefaultForMissingKey(): void
    {
        $this->assertSame('fallback', $this->service->get('nonexistent', 'fallback'));
    }

    public function testGetReturnsStoredValue(): void
    {
        $this->service->set('site_name', 'My Scout Group');

        $this->assertSame('My Scout Group', $this->service->get('site_name'));
    }

    public function testGetIgnoresDefaultWhenKeyExists(): void
    {
        $this->service->set('debug', '1');

        $this->assertSame('1', $this->service->get('debug', 'default'));
    }

    // ── set() ────────────────────────────────────────────────────────────

    public function testSetCreatesNewSetting(): void
    {
        $this->service->set('timezone', 'Europe/London', 'general');

        $row = $this->db->fetchOne("SELECT * FROM `settings` WHERE `key` = 'timezone'");
        $this->assertNotNull($row);
        $this->assertSame('Europe/London', $row['value']);
        $this->assertSame('general', $row['group']);
    }

    public function testSetUpdatesExistingValue(): void
    {
        $this->service->set('max_members', '100');
        $this->service->set('max_members', '200');

        $value = $this->service->get('max_members');
        $this->assertSame('200', $value);

        // Still only one row
        $count = $this->db->fetchColumn("SELECT COUNT(*) FROM `settings` WHERE `key` = 'max_members'");
        $this->assertSame(1, (int) $count);
    }

    public function testSetEncodesBooolTrueAsOne(): void
    {
        $this->service->set('registration_enabled', true);
        $this->assertSame('1', $this->service->get('registration_enabled'));
    }

    public function testSetEncodesBoolFalseAsZero(): void
    {
        $this->service->set('maintenance_mode', false);
        $this->assertSame('0', $this->service->get('maintenance_mode'));
    }

    public function testSetEncodesArrayAsJson(): void
    {
        $this->service->set('allowed_roles', ['admin', 'editor']);
        $value = $this->service->get('allowed_roles');
        $decoded = json_decode($value, true);
        $this->assertSame(['admin', 'editor'], $decoded);
    }

    public function testSetStoresNullAsNull(): void
    {
        $this->service->set('optional_key', null);
        $this->assertNull($this->service->get('optional_key', 'sentinel'));
    }

    public function testSetUpdatesGroupOnUpsert(): void
    {
        $this->service->set('smtp_host', 'smtp.example.com', 'general');
        $this->service->set('smtp_host', 'smtp2.example.com', 'smtp');

        $row = $this->db->fetchOne("SELECT `group` FROM `settings` WHERE `key` = 'smtp_host'");
        $this->assertSame('smtp', $row['group']);
    }

    public function testSetDefaultsGroupToGeneral(): void
    {
        $this->service->set('foo', 'bar');

        $row = $this->db->fetchOne("SELECT `group` FROM `settings` WHERE `key` = 'foo'");
        $this->assertSame('general', $row['group']);
    }

    // ── getGroup() ───────────────────────────────────────────────────────

    public function testGetGroupReturnsKeyValueMapForGroup(): void
    {
        $this->service->set('smtp_host', 'mail.example.com', 'smtp');
        $this->service->set('smtp_port', '587', 'smtp');
        $this->service->set('site_name', 'Scouts', 'general');

        $smtpSettings = $this->service->getGroup('smtp');

        $this->assertArrayHasKey('smtp_host', $smtpSettings);
        $this->assertArrayHasKey('smtp_port', $smtpSettings);
        $this->assertArrayNotHasKey('site_name', $smtpSettings);
        $this->assertSame('mail.example.com', $smtpSettings['smtp_host']);
        $this->assertSame('587', $smtpSettings['smtp_port']);
    }

    public function testGetGroupReturnsEmptyArrayForUnknownGroup(): void
    {
        $this->assertSame([], $this->service->getGroup('nonexistent_group'));
    }

    // ── getAll() ─────────────────────────────────────────────────────────

    public function testGetAllReturnsNestedGroupedArray(): void
    {
        $this->service->set('site_name', 'Scouts', 'general');
        $this->service->set('smtp_host', 'mail.example.com', 'smtp');
        $this->service->set('smtp_port', '587', 'smtp');

        $all = $this->service->getAll();

        $this->assertArrayHasKey('general', $all);
        $this->assertArrayHasKey('smtp', $all);
        $this->assertSame('Scouts', $all['general']['site_name']);
        $this->assertSame('mail.example.com', $all['smtp']['smtp_host']);
    }

    public function testGetAllReturnsEmptyArrayWhenNoSettings(): void
    {
        $this->assertSame([], $this->service->getAll());
    }

    // ── setMultiple() ────────────────────────────────────────────────────

    public function testSetMultipleWritesAllPairsToDatabase(): void
    {
        $this->service->setMultiple([
            'host'     => 'smtp.example.com',
            'port'     => '587',
            'from'     => 'noreply@example.com',
        ], 'smtp');

        $group = $this->service->getGroup('smtp');

        $this->assertSame('smtp.example.com', $group['host']);
        $this->assertSame('587', $group['port']);
        $this->assertSame('noreply@example.com', $group['from']);
    }

    public function testSetMultipleUpsertsExistingKeys(): void
    {
        $this->service->set('debug', 'false', 'app');
        $this->service->setMultiple(['debug' => 'true'], 'app');

        $this->assertSame('true', $this->service->get('debug'));

        $count = $this->db->fetchColumn("SELECT COUNT(*) FROM `settings` WHERE `key` = 'debug'");
        $this->assertSame(1, (int) $count);
    }

    public function testSetMultipleWithEmptyArrayDoesNothing(): void
    {
        $this->service->setMultiple([], 'general');

        $count = $this->db->fetchColumn("SELECT COUNT(*) FROM `settings`");
        $this->assertSame(0, (int) $count);
    }

    // ── delete() ─────────────────────────────────────────────────────────

    public function testDeleteRemovesSettingFromDatabase(): void
    {
        $this->service->set('temp_key', 'temp_value');
        $this->assertSame('temp_value', $this->service->get('temp_key'));

        $this->service->delete('temp_key');

        $this->assertNull($this->service->get('temp_key'));
    }

    public function testDeleteNonExistentKeyDoesNotThrow(): void
    {
        // Should complete silently without throwing
        $this->service->delete('does_not_exist');
        $this->assertTrue(true);
    }

    public function testDeleteOnlyRemovesTargetedKey(): void
    {
        $this->service->set('keep_me', 'value1');
        $this->service->set('remove_me', 'value2');

        $this->service->delete('remove_me');

        $this->assertSame('value1', $this->service->get('keep_me'));
        $this->assertNull($this->service->get('remove_me'));
    }
}

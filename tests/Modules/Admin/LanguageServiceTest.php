<?php

declare(strict_types=1);

namespace Tests\Modules\Admin;

use App\Core\Database;
use App\Modules\Admin\Services\LanguageService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LanguageService.
 *
 * Covers language CRUD (activate/deactivate/setDefault), filesystem sync,
 * override upsert/clear, getStringsForLanguage merge, calculateCompletion,
 * and uploadLanguage validation.
 */
class LanguageServiceTest extends TestCase
{
    private Database $db;
    private LanguageService $service;
    private string $langPath;

    protected function setUp(): void
    {
        try {
            $this->db = new Database(TEST_CONFIG['db']);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        // Temp lang directory with a master en.json
        $this->langPath = sys_get_temp_dir() . '/sk10_lang_test_' . uniqid();
        mkdir($this->langPath, 0755, true);

        $masterStrings = [
            'nav.home'    => 'Home',
            'nav.members' => 'Members',
            'nav.events'  => 'Events',
            'btn.save'    => 'Save',
            'btn.cancel'  => 'Cancel',
        ];
        file_put_contents(
            $this->langPath . '/en.json',
            json_encode($masterStrings, JSON_PRETTY_PRINT)
        );

        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->query('DROP TABLE IF EXISTS `i18n_overrides`');
        $this->db->query('DROP TABLE IF EXISTS `languages`');
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');

        $this->db->query("
            CREATE TABLE `languages` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `code` VARCHAR(10) NOT NULL UNIQUE,
                `name` VARCHAR(100) NOT NULL,
                `native_name` VARCHAR(100) NOT NULL DEFAULT '',
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `is_default` TINYINT(1) NOT NULL DEFAULT 0,
                `completion_pct` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                `source` VARCHAR(20) NOT NULL DEFAULT 'bundled',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE `i18n_overrides` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `language_code` VARCHAR(10) NOT NULL,
                `string_key` VARCHAR(200) NOT NULL,
                `value` TEXT NOT NULL,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_lang_key` (`language_code`, `string_key`),
                CONSTRAINT `fk_override_lang` FOREIGN KEY (`language_code`) REFERENCES `languages` (`code`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Seed the default English language
        $this->db->insert('languages', [
            'code'           => 'en',
            'name'           => 'English',
            'native_name'    => 'English',
            'is_active'      => 1,
            'is_default'     => 1,
            'completion_pct' => 100.00,
            'source'         => 'bundled',
        ]);

        $this->service = new LanguageService($this->db, $this->langPath);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
            $this->db->query('DROP TABLE IF EXISTS `i18n_overrides`');
            $this->db->query('DROP TABLE IF EXISTS `languages`');
            $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        }

        $this->removeDir($this->langPath);
    }

    // ── getLanguages() ───────────────────────────────────────────────────

    public function testGetLanguagesReturnsAllLanguages(): void
    {
        $langs = $this->service->getLanguages();
        $this->assertCount(1, $langs);
        $this->assertSame('en', $langs[0]['code']);
    }

    public function testGetLanguagesReturnsDefaultFirst(): void
    {
        $this->db->insert('languages', [
            'code' => 'mt', 'name' => 'Maltese', 'native_name' => 'Malti',
            'is_active' => 1, 'is_default' => 0, 'completion_pct' => 80.0, 'source' => 'uploaded',
        ]);

        $langs = $this->service->getLanguages();
        $this->assertSame('en', $langs[0]['code']);
    }

    // ── getActiveLanguages() ─────────────────────────────────────────────

    public function testGetActiveLanguagesExcludesInactive(): void
    {
        $this->db->insert('languages', [
            'code' => 'fr', 'name' => 'French', 'native_name' => 'Français',
            'is_active' => 0, 'is_default' => 0, 'completion_pct' => 50.0, 'source' => 'uploaded',
        ]);

        $active = $this->service->getActiveLanguages();

        $codes = array_column($active, 'code');
        $this->assertContains('en', $codes);
        $this->assertNotContains('fr', $codes);
    }

    // ── getDefaultLanguage() ─────────────────────────────────────────────

    public function testGetDefaultLanguageReturnsDefaultCode(): void
    {
        $this->assertSame('en', $this->service->getDefaultLanguage());
    }

    public function testGetDefaultLanguageFallsBackToEnWhenNoneSet(): void
    {
        $this->db->query("UPDATE `languages` SET `is_default` = 0");
        $this->assertSame('en', $this->service->getDefaultLanguage());
    }

    // ── setDefault() ────────────────────────────────────────────────────

    public function testSetDefaultChangesDefaultLanguage(): void
    {
        $this->db->insert('languages', [
            'code' => 'mt', 'name' => 'Maltese', 'native_name' => 'Malti',
            'is_active' => 1, 'is_default' => 0, 'completion_pct' => 80.0, 'source' => 'uploaded',
        ]);

        $this->service->setDefault('mt');

        $this->assertSame('mt', $this->service->getDefaultLanguage());
    }

    public function testSetDefaultUnsetsOldDefault(): void
    {
        $this->db->insert('languages', [
            'code' => 'mt', 'name' => 'Maltese', 'native_name' => 'Malti',
            'is_active' => 1, 'is_default' => 0, 'completion_pct' => 80.0, 'source' => 'uploaded',
        ]);

        $this->service->setDefault('mt');

        $enRow = $this->db->fetchOne("SELECT is_default FROM `languages` WHERE code = 'en'");
        $this->assertSame(0, (int) $enRow['is_default']);
    }

    public function testSetDefaultActivatesLanguage(): void
    {
        $this->db->insert('languages', [
            'code' => 'mt', 'name' => 'Maltese', 'native_name' => 'Malti',
            'is_active' => 0, 'is_default' => 0, 'completion_pct' => 80.0, 'source' => 'uploaded',
        ]);

        $this->service->setDefault('mt');

        $row = $this->db->fetchOne("SELECT is_active FROM `languages` WHERE code = 'mt'");
        $this->assertSame(1, (int) $row['is_active']);
    }

    public function testSetDefaultThrowsForUnknownCode(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->setDefault('xx');
    }

    // ── activate() / deactivate() ────────────────────────────────────────

    public function testActivateSetsIsActiveToOne(): void
    {
        $this->db->insert('languages', [
            'code' => 'fr', 'name' => 'French', 'native_name' => 'Français',
            'is_active' => 0, 'is_default' => 0, 'completion_pct' => 60.0, 'source' => 'uploaded',
        ]);

        $this->service->activate('fr');

        $row = $this->db->fetchOne("SELECT is_active FROM `languages` WHERE code = 'fr'");
        $this->assertSame(1, (int) $row['is_active']);
    }

    public function testDeactivateSetsIsActiveToZero(): void
    {
        $this->db->insert('languages', [
            'code' => 'fr', 'name' => 'French', 'native_name' => 'Français',
            'is_active' => 1, 'is_default' => 0, 'completion_pct' => 60.0, 'source' => 'uploaded',
        ]);

        $this->service->deactivate('fr');

        $row = $this->db->fetchOne("SELECT is_active FROM `languages` WHERE code = 'fr'");
        $this->assertSame(0, (int) $row['is_active']);
    }

    public function testDeactivateThrowsForEnglish(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/English.*fallback/i');

        $this->service->deactivate('en');
    }

    public function testDeactivateThrowsForDefaultLanguage(): void
    {
        $this->db->insert('languages', [
            'code' => 'mt', 'name' => 'Maltese', 'native_name' => 'Malti',
            'is_active' => 1, 'is_default' => 1, 'completion_pct' => 80.0, 'source' => 'uploaded',
        ]);
        $this->db->query("UPDATE `languages` SET `is_default` = 0 WHERE code = 'en'");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/default/i');

        $this->service->deactivate('mt');
    }

    // ── uploadLanguage() ─────────────────────────────────────────────────

    public function testUploadLanguageCreatesFileAndDbRecord(): void
    {
        $translations = ['nav.home' => 'Dar', 'nav.members' => 'Membri'];

        $this->service->uploadLanguage('mt', 'Maltese', 'Malti', $translations);

        $this->assertFileExists($this->langPath . '/mt.json');

        $row = $this->db->fetchOne("SELECT * FROM `languages` WHERE code = 'mt'");
        $this->assertNotNull($row);
        $this->assertSame('Maltese', $row['name']);
        $this->assertSame('Malti', $row['native_name']);
        $this->assertSame('uploaded', $row['source']);
    }

    public function testUploadLanguageCalculatesCompletionPercent(): void
    {
        // 2 out of 5 master keys translated = 40%
        $translations = ['nav.home' => 'Dar', 'nav.members' => 'Membri'];

        $this->service->uploadLanguage('mt', 'Maltese', 'Malti', $translations);

        $row = $this->db->fetchOne("SELECT completion_pct FROM `languages` WHERE code = 'mt'");
        $this->assertSame(40.0, (float) $row['completion_pct']);
    }

    public function testUploadLanguageFiltersOutInvalidKeys(): void
    {
        $translations = ['nav.home' => 'Dar', 'nonexistent.key' => 'garbage'];

        $this->service->uploadLanguage('mt', 'Maltese', 'Malti', $translations);

        $content = json_decode(file_get_contents($this->langPath . '/mt.json'), true);
        $this->assertArrayHasKey('nav.home', $content);
        $this->assertArrayNotHasKey('nonexistent.key', $content);
    }

    public function testUploadLanguageUpdatesExistingRecord(): void
    {
        $this->db->insert('languages', [
            'code' => 'mt', 'name' => 'Old Name', 'native_name' => 'Old',
            'is_active' => 1, 'is_default' => 0, 'completion_pct' => 0.0, 'source' => 'bundled',
        ]);

        $this->service->uploadLanguage('mt', 'Maltese Updated', 'Malti', ['nav.home' => 'Dar']);

        $row = $this->db->fetchOne("SELECT name FROM `languages` WHERE code = 'mt'");
        $this->assertSame('Maltese Updated', $row['name']);
    }

    public function testUploadLanguageThrowsForInvalidCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/code/i');

        $this->service->uploadLanguage('INVALID', 'Name', 'Native', []);
    }

    public function testUploadLanguageAcceptsTwoPartCode(): void
    {
        $this->service->uploadLanguage('zh-CN', 'Chinese Simplified', '简体中文', ['nav.home' => '主页']);

        $row = $this->db->fetchOne("SELECT code FROM `languages` WHERE code = 'zh-CN'");
        $this->assertNotNull($row);
        @unlink($this->langPath . '/zh-CN.json');
    }

    // ── calculateCompletion() ────────────────────────────────────────────

    public function testCalculateCompletionReturnsZeroForMissingFile(): void
    {
        $pct = $this->service->calculateCompletion('xx');
        $this->assertSame(0.0, $pct);
    }

    public function testCalculateCompletionReturnsFullForEnglish(): void
    {
        $pct = $this->service->calculateCompletion('en');
        $this->assertSame(100.0, $pct);
    }

    public function testCalculateCompletionReturnsPartialCompletion(): void
    {
        $partial = ['nav.home' => 'Dar', 'nav.members' => 'Membri']; // 2/5 = 40%
        file_put_contents($this->langPath . '/mt.json', json_encode($partial));

        $pct = $this->service->calculateCompletion('mt');
        $this->assertSame(40.0, $pct);
    }

    // ── getOverrides() / setOverride() / clearOverride() ─────────────────

    public function testSetOverrideCreatesNewOverride(): void
    {
        $this->service->setOverride('en', 'nav.home', 'Dashboard');

        $overrides = $this->service->getOverrides('en');
        $this->assertCount(1, $overrides);
        $this->assertSame('nav.home', $overrides[0]['string_key']);
        $this->assertSame('Dashboard', $overrides[0]['value']);
    }

    public function testSetOverrideUpdatesExistingOverride(): void
    {
        $this->service->setOverride('en', 'nav.home', 'Dashboard');
        $this->service->setOverride('en', 'nav.home', 'Home Page');

        $overrides = $this->service->getOverrides('en');
        $this->assertCount(1, $overrides);
        $this->assertSame('Home Page', $overrides[0]['value']);
    }

    public function testClearOverrideRemovesOverride(): void
    {
        $this->service->setOverride('en', 'btn.save', 'Submit');
        $this->assertCount(1, $this->service->getOverrides('en'));

        $this->service->clearOverride('en', 'btn.save');

        $this->assertSame([], $this->service->getOverrides('en'));
    }

    public function testClearOverrideForNonExistentKeyDoesNotThrow(): void
    {
        $this->service->clearOverride('en', 'nonexistent.key');
        $this->assertTrue(true);
    }

    public function testGetOverridesReturnsEmptyForLanguageWithNoOverrides(): void
    {
        $this->assertSame([], $this->service->getOverrides('en'));
    }

    // ── getStringsForLanguage() ───────────────────────────────────────────

    public function testGetStringsForLanguageReturnsAllMasterKeys(): void
    {
        $strings = $this->service->getStringsForLanguage('en');

        $keys = array_column($strings, 'key');
        $this->assertContains('nav.home', $keys);
        $this->assertContains('btn.save', $keys);
        $this->assertCount(5, $strings);
    }

    public function testGetStringsForLanguageMarksOverriddenStrings(): void
    {
        $this->service->setOverride('en', 'btn.save', 'Submit');

        $strings = $this->service->getStringsForLanguage('en');
        $saveEntry = array_values(array_filter($strings, fn($s) => $s['key'] === 'btn.save'))[0];

        $this->assertTrue($saveEntry['has_override']);
        $this->assertSame('Submit', $saveEntry['translated']);
    }

    public function testGetStringsForLanguagePrioritisesOverrideOverFileTranslation(): void
    {
        // Create a mt.json with a translation for nav.home
        file_put_contents($this->langPath . '/mt.json', json_encode(['nav.home' => 'Dar']));
        $this->db->insert('languages', [
            'code' => 'mt', 'name' => 'Maltese', 'native_name' => 'Malti',
            'is_active' => 1, 'is_default' => 0, 'completion_pct' => 20.0, 'source' => 'uploaded',
        ]);

        // Override nav.home with a different value
        $this->service->setOverride('mt', 'nav.home', 'Override Value');

        $strings = $this->service->getStringsForLanguage('mt');
        $homeEntry = array_values(array_filter($strings, fn($s) => $s['key'] === 'nav.home'))[0];

        $this->assertSame('Override Value', $homeEntry['translated']);
        $this->assertTrue($homeEntry['has_override']);
    }

    public function testGetStringsForLanguageUsesFileTranslationWhenNoOverride(): void
    {
        file_put_contents($this->langPath . '/mt.json', json_encode(['nav.home' => 'Dar']));
        $this->db->insert('languages', [
            'code' => 'mt', 'name' => 'Maltese', 'native_name' => 'Malti',
            'is_active' => 1, 'is_default' => 0, 'completion_pct' => 20.0, 'source' => 'uploaded',
        ]);

        $strings = $this->service->getStringsForLanguage('mt');
        $homeEntry = array_values(array_filter($strings, fn($s) => $s['key'] === 'nav.home'))[0];

        $this->assertSame('Dar', $homeEntry['translated']);
        $this->assertFalse($homeEntry['has_override']);
    }

    public function testGetStringsForLanguageReturnsEmptyTranslationForMissingFile(): void
    {
        // Request strings for a language with no file
        $strings = $this->service->getStringsForLanguage('xx');

        foreach ($strings as $entry) {
            $this->assertSame('', $entry['translated']);
            $this->assertFalse($entry['has_override']);
        }
    }

    // ── syncFromFilesystem() ─────────────────────────────────────────────

    public function testSyncFromFilesystemRegistersNewFile(): void
    {
        file_put_contents($this->langPath . '/mt.json', json_encode(['nav.home' => 'Dar']));

        $this->service->syncFromFilesystem();

        $row = $this->db->fetchOne("SELECT * FROM `languages` WHERE code = 'mt'");
        $this->assertNotNull($row);
        $this->assertSame('MT', $row['name']); // placeholder
    }

    public function testSyncFromFilesystemUpdatesCompletionPct(): void
    {
        // 1 out of 5 keys = 20%
        file_put_contents($this->langPath . '/mt.json', json_encode(['nav.home' => 'Dar']));
        $this->db->insert('languages', [
            'code' => 'mt', 'name' => 'Maltese', 'native_name' => 'Malti',
            'is_active' => 1, 'is_default' => 0, 'completion_pct' => 0.0, 'source' => 'uploaded',
        ]);

        $this->service->syncFromFilesystem();

        $row = $this->db->fetchOne("SELECT completion_pct FROM `languages` WHERE code = 'mt'");
        $this->assertSame(20.0, (float) $row['completion_pct']);
    }

    public function testSyncFromFilesystemRemovesOrphanedDbRecords(): void
    {
        // Stale DB record for a language file that no longer exists
        $this->db->insert('languages', [
            'code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch',
            'is_active' => 1, 'is_default' => 0, 'completion_pct' => 80.0, 'source' => 'bundled',
        ]);

        $this->service->syncFromFilesystem();

        $row = $this->db->fetchOne("SELECT code FROM `languages` WHERE code = 'de'");
        $this->assertNull($row);
    }

    public function testSyncFromFilesystemPreservesEnglishEvenIfFileMissing(): void
    {
        // English is a safety-net: never deleted even if en.json is absent
        $this->service->syncFromFilesystem();

        $row = $this->db->fetchOne("SELECT code FROM `languages` WHERE code = 'en'");
        $this->assertNotNull($row);
    }

    // ── exportMasterFile() ───────────────────────────────────────────────

    public function testExportMasterFileReturnsValidJson(): void
    {
        $json = $this->service->exportMasterFile();
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('nav.home', $decoded);
        $this->assertSame('Home', $decoded['nav.home']);
    }

    // ── Private helpers ──────────────────────────────────────────────────

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}

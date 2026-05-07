<?php

declare(strict_types=1);

namespace Tests\Modules\Admin;

use App\Core\Database;
use App\Modules\Admin\Services\BackupService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for BackupService.
 *
 * Covers dumpDatabase output, backup creation (ZIP), listing, download
 * path resolution, deletion, and filename validation (path traversal guard).
 */
class BackupServiceTest extends TestCase
{
    private Database $db;
    private BackupService $service;
    private string $dataPath;

    protected function setUp(): void
    {
        try {
            $this->db = new Database(TEST_CONFIG['db']);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        $this->dataPath = sys_get_temp_dir() . '/sk10_backup_test_' . uniqid();
        mkdir($this->dataPath . '/backups', 0755, true);

        $this->service = new BackupService($this->db, $this->dataPath);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dataPath);
    }

    // ── dumpDatabase() ───────────────────────────────────────────────────

    public function testDumpDatabaseReturnsString(): void
    {
        $dump = $this->service->dumpDatabase();
        $this->assertIsString($dump);
        $this->assertNotEmpty($dump);
    }

    public function testDumpDatabaseContainsHeader(): void
    {
        $dump = $this->service->dumpDatabase();
        $this->assertStringContainsString('ScoutKeeper database backup', $dump);
        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS = 0', $dump);
        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS = 1', $dump);
    }

    public function testDumpDatabaseContainsCreateTableStatement(): void
    {
        // Ensure at least one table exists in the test DB
        $this->db->query('DROP TABLE IF EXISTS `backup_test_table`');
        $this->db->query("
            CREATE TABLE `backup_test_table` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $dump = $this->service->dumpDatabase();

        $this->assertStringContainsString('backup_test_table', $dump);
        $this->assertStringContainsString('DROP TABLE IF EXISTS', $dump);
        $this->assertStringContainsString('CREATE TABLE', $dump);

        $this->db->query('DROP TABLE IF EXISTS `backup_test_table`');
    }

    public function testDumpDatabaseIncludesInsertStatements(): void
    {
        $this->db->query('DROP TABLE IF EXISTS `backup_seed_table`');
        $this->db->query("
            CREATE TABLE `backup_seed_table` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `value` VARCHAR(100) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $this->db->query("INSERT INTO `backup_seed_table` (`value`) VALUES ('hello')");

        $dump = $this->service->dumpDatabase();

        $this->assertStringContainsString('INSERT INTO `backup_seed_table`', $dump);
        $this->assertStringContainsString('hello', $dump);

        $this->db->query('DROP TABLE IF EXISTS `backup_seed_table`');
    }

    public function testDumpDatabaseHandlesEmptyTable(): void
    {
        $this->db->query('DROP TABLE IF EXISTS `backup_empty_table`');
        $this->db->query("
            CREATE TABLE `backup_empty_table` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $dump = $this->service->dumpDatabase();

        $this->assertStringContainsString('backup_empty_table', $dump);
        $this->assertStringContainsString('No data in backup_empty_table', $dump);

        $this->db->query('DROP TABLE IF EXISTS `backup_empty_table`');
    }

    // ── createBackup() ───────────────────────────────────────────────────

    public function testCreateBackupReturnsFilename(): void
    {
        $filename = $this->service->createBackup();

        $this->assertMatchesRegularExpression('/^backup_\d{8}_\d{6}\.zip$/', $filename);
    }

    public function testCreateBackupCreatesZipFile(): void
    {
        $filename = $this->service->createBackup();
        $path = $this->dataPath . '/backups/' . $filename;

        $this->assertFileExists($path);
        $this->assertGreaterThan(0, filesize($path));
    }

    public function testCreateBackupZipContainsDatabaseSql(): void
    {
        $filename = $this->service->createBackup();
        $path = $this->dataPath . '/backups/' . $filename;

        $zip = new \ZipArchive();
        $result = $zip->open($path);
        $this->assertTrue($result, 'ZIP should open successfully');

        $index = $zip->locateName('database.sql');
        $this->assertNotFalse($index, 'ZIP must contain database.sql');

        $content = $zip->getFromName('database.sql');
        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS', $content);

        $zip->close();
    }

    public function testCreateBackupCreatesBackupsDirIfMissing(): void
    {
        $newDataPath = sys_get_temp_dir() . '/sk10_newpath_' . uniqid();
        mkdir($newDataPath, 0755, true);

        $service = new BackupService($this->db, $newDataPath);
        $filename = $service->createBackup();

        $this->assertFileExists($newDataPath . '/backups/' . $filename);

        $this->removeDir($newDataPath);
    }

    // ── listBackups() ────────────────────────────────────────────────────

    public function testListBackupsReturnsEmptyWhenNoBackups(): void
    {
        $this->assertSame([], $this->service->listBackups());
    }

    public function testListBackupsReturnsBackupMetadata(): void
    {
        $filename = $this->service->createBackup();

        $backups = $this->service->listBackups();

        $this->assertCount(1, $backups);
        $this->assertSame($filename, $backups[0]['filename']);
        $this->assertArrayHasKey('size', $backups[0]);
        $this->assertArrayHasKey('size_human', $backups[0]);
        $this->assertArrayHasKey('date', $backups[0]);
        $this->assertGreaterThan(0, $backups[0]['size']);
    }

    public function testListBackupsReturnsNewestFirst(): void
    {
        // Create two files with different mtimes
        $dir = $this->dataPath . '/backups';
        $older = $dir . '/backup_20240101_120000.zip';
        $newer = $dir . '/backup_20240601_120000.zip';

        file_put_contents($older, 'old');
        touch($older, strtotime('2024-01-01 12:00:00'));
        file_put_contents($newer, 'new');
        touch($newer, strtotime('2024-06-01 12:00:00'));

        $backups = $this->service->listBackups();

        $this->assertCount(2, $backups);
        $this->assertSame('backup_20240601_120000.zip', $backups[0]['filename']);
        $this->assertSame('backup_20240101_120000.zip', $backups[1]['filename']);
    }

    public function testListBackupsIgnoresNonMatchingFiles(): void
    {
        $dir = $this->dataPath . '/backups';
        file_put_contents($dir . '/README.txt', 'ignore me');
        file_put_contents($dir . '/notabackup.zip', 'ignore me too');
        $this->service->createBackup();

        $backups = $this->service->listBackups();

        // Only the one proper backup_YYYYMMDD_HHMMSS.zip should appear
        $this->assertCount(1, $backups);
        $this->assertMatchesRegularExpression('/^backup_\d{8}_\d{6}\.zip$/', $backups[0]['filename']);
    }

    public function testListBackupsReturnsEmptyWhenDirDoesNotExist(): void
    {
        $service = new BackupService($this->db, '/nonexistent/path/abc');
        $this->assertSame([], $service->listBackups());
    }

    // ── getBackupPath() ──────────────────────────────────────────────────

    public function testGetBackupPathReturnsFullPathForExistingFile(): void
    {
        $filename = $this->service->createBackup();
        $path = $this->service->getBackupPath($filename);

        $this->assertNotNull($path);
        $this->assertStringContainsString($filename, $path);
        $this->assertFileExists($path);
    }

    public function testGetBackupPathReturnsNullForMissingFile(): void
    {
        $result = $this->service->getBackupPath('backup_20200101_000000.zip');
        $this->assertNull($result);
    }

    public function testGetBackupPathRejectsInvalidFilename(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->getBackupPath('../../etc/passwd');
    }

    public function testGetBackupPathRejectsFilenameWithoutBackupPrefix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->getBackupPath('arbitrary_file.zip');
    }

    // ── deleteBackup() ───────────────────────────────────────────────────

    public function testDeleteBackupRemovesFile(): void
    {
        $filename = $this->service->createBackup();
        $path = $this->dataPath . '/backups/' . $filename;

        $this->assertFileExists($path);

        $this->service->deleteBackup($filename);

        $this->assertFileDoesNotExist($path);
    }

    public function testDeleteBackupThrowsForMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/i');

        $this->service->deleteBackup('backup_20200101_000000.zip');
    }

    public function testDeleteBackupThrowsForInvalidFilename(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->deleteBackup('../../../etc/passwd');
    }

    public function testDeleteBackupRejectsFilenameWithPathSeparator(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->deleteBackup('backup_20200101_000000/evil.zip');
    }

    public function testDeleteBackupRejectsDotDotSequence(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->deleteBackup('backup_..20200101_000000.zip');
    }

    // ── Filename validation edge cases ───────────────────────────────────

    public function testValidFilenameFormatIsAccepted(): void
    {
        // Create a file and verify it can be retrieved (no exception)
        $filename = $this->service->createBackup();
        $path = $this->service->getBackupPath($filename);
        $this->assertNotNull($path);
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

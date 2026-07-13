<?php

declare(strict_types=1);

namespace Tests\Updater;

use PHPUnit\Framework\TestCase;
use Updater\UpdateManager;

/**
 * Tests for the Auto-Update Manager (Phase 6.2).
 *
 * Uses a temporary directory to simulate the project root for
 * filesystem-based operations (maintenance mode, state, tokens).
 */
class UpdateManagerTest extends TestCase
{
    private string $tempDir;
    private UpdateManager $updater;

    protected function setUp(): void
    {
        // Load the UpdateManager class (it's not namespaced, lives in /updater/)
        require_once ROOT_PATH . '/updater/UpdateManager.php';

        $this->tempDir = sys_get_temp_dir() . '/sk10_updater_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        mkdir($this->tempDir . '/var/updates', 0755, true);
        mkdir($this->tempDir . '/var/logs', 0755, true);
        mkdir($this->tempDir . '/config', 0755, true);

        $this->updater = new UpdateManager($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    // ── Maintenance Mode ────────────────────────────────────────────

    public function testSetMaintenanceModeCreatesFlag(): void
    {
        $flagFile = $this->tempDir . '/var/maintenance.flag';
        $this->assertFileDoesNotExist($flagFile);

        $this->updater->setMaintenanceMode(true);

        $this->assertFileExists($flagFile);
        $this->assertNotEmpty(file_get_contents($flagFile));
    }

    public function testSetMaintenanceModeRemovesFlag(): void
    {
        $flagFile = $this->tempDir . '/var/maintenance.flag';
        file_put_contents($flagFile, date('c'));

        $this->updater->setMaintenanceMode(false);

        $this->assertFileDoesNotExist($flagFile);
    }

    public function testSetMaintenanceModeOffWhenNoFlagExists(): void
    {
        // Should not throw when flag doesn't exist
        $this->updater->setMaintenanceMode(false);
        $this->assertFileDoesNotExist($this->tempDir . '/var/maintenance.flag');
    }

    // ── Update State ────────────────────────────────────────────────

    public function testGetUpdateStateReturnsNullWhenNoState(): void
    {
        $this->assertNull($this->updater->getUpdateState());
    }

    public function testStateIsPersisted(): void
    {
        // Use token generation to write state
        $this->updater->generateUpdateToken();

        $state = $this->updater->getUpdateState();
        $this->assertIsArray($state);
        $this->assertArrayHasKey('update_token', $state);
        $this->assertArrayHasKey('updated_at', $state);
    }

    // ── Update Tokens ───────────────────────────────────────────────

    public function testGenerateUpdateTokenReturnsHexString(): void
    {
        $token = $this->updater->generateUpdateToken();

        $this->assertSame(64, strlen($token)); // 32 bytes = 64 hex chars
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function testVerifyUpdateTokenAcceptsValidToken(): void
    {
        $token = $this->updater->generateUpdateToken();

        $this->assertTrue($this->updater->verifyUpdateToken($token));
    }

    public function testVerifyUpdateTokenRejectsInvalidToken(): void
    {
        $this->updater->generateUpdateToken();

        $this->assertFalse($this->updater->verifyUpdateToken('wrong-token'));
    }

    public function testVerifyUpdateTokenRejectsWhenNoState(): void
    {
        $this->assertFalse($this->updater->verifyUpdateToken('any-token'));
    }

    public function testVerifyUpdateTokenRejectsExpiredToken(): void
    {
        $token = $this->updater->generateUpdateToken();

        // Manually expire the token by setting created_at to >1 hour ago
        $stateFile = $this->tempDir . '/var/updates/update_state.json';
        $state = json_decode(file_get_contents($stateFile), true);
        $state['token_created_at'] = time() - 3700; // 1 hour + margin
        file_put_contents($stateFile, json_encode($state));

        $this->assertFalse($this->updater->verifyUpdateToken($token));
    }

    public function testEachTokenIsUnique(): void
    {
        $token1 = $this->updater->generateUpdateToken();
        $token2 = $this->updater->generateUpdateToken();

        $this->assertNotSame($token1, $token2);
    }

    // ── Signature Verification ──────────────────────────────────────

    public function testVerifySignatureReturnsTrueWhenNoPublicKey(): void
    {
        // When no public key file exists, verification is skipped (self-hosted)
        $zipPath = $this->tempDir . '/test.zip';
        $sigPath = $this->tempDir . '/test.zip.sig';
        file_put_contents($zipPath, 'fake zip content');
        file_put_contents($sigPath, 'fake signature');

        $this->assertTrue($this->updater->verifySignature($zipPath, $sigPath));
    }

    public function testVerifySignatureReturnsFalseForMissingFiles(): void
    {
        // Create a dummy public key file so verification is attempted
        file_put_contents($this->tempDir . '/public-key.pem', 'dummy');

        $this->assertFalse($this->updater->verifySignature(
            $this->tempDir . '/nonexistent.zip',
            $this->tempDir . '/nonexistent.sig'
        ));
    }

    public function testVerifySignatureReturnsFalseForInvalidKey(): void
    {
        file_put_contents($this->tempDir . '/public-key.pem', 'not a real PEM key');
        $zipPath = $this->tempDir . '/test.zip';
        $sigPath = $this->tempDir . '/test.zip.sig';
        file_put_contents($zipPath, 'fake zip content');
        file_put_contents($sigPath, 'fake signature');

        $this->assertFalse($this->updater->verifySignature($zipPath, $sigPath));
    }

    // ── checkForUpdate ──────────────────────────────────────────────

    public function testCheckForUpdateReturnsNullOnNetworkFailure(): void
    {
        // Using a version that is absurdly high ensures no update is found
        // even if the GitHub API happens to respond
        $result = $this->updater->checkForUpdate('999.999.999');

        // Either null (can't reach API) or null (no newer version)
        $this->assertNull($result);
    }

    // ── getCurrentVersion ───────────────────────────────────────────

    public function testGetCurrentVersionReturnsDefaultWhenNoConfig(): void
    {
        $version = $this->updater->getCurrentVersion();

        // No config exists, should return 'unknown'
        $this->assertSame('unknown', $version);
    }

    public function testGetCurrentVersionReadsFromVersionFile(): void
    {
        // VERSION file is the primary source of truth
        file_put_contents($this->tempDir . '/VERSION', "2.5.0\n");

        $version = $this->updater->getCurrentVersion();
        $this->assertSame('2.5.0', $version);
    }

    // ── downloadRelease ─────────────────────────────────────────────

    public function testDownloadReleaseCreatesDirectoryAndFile(): void
    {
        $targetPath = $this->tempDir . '/downloads/subdir/test.zip';

        // Use a URL that won't resolve — we just test the directory creation
        $result = $this->updater->downloadRelease('http://localhost:1/nonexistent.zip', $targetPath);

        // Should fail (no server) but the directory should have been created
        $this->assertFalse($result);
        $this->assertDirectoryExists(dirname($targetPath));
    }

    // ── applyUpdate ─────────────────────────────────────────────────

    public function testApplyUpdateFailsWithNoConfig(): void
    {
        $result = $this->updater->applyUpdate($this->tempDir . '/fake.zip');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('config', $result['error']);
    }

    // ── rollback ────────────────────────────────────────────────────

    public function testRollbackFailsWithNoState(): void
    {
        $this->assertFalse($this->updater->rollback());
    }

    public function testRollbackFailsWithMissingBackupDir(): void
    {
        // Write state with a non-existent backup path
        $stateFile = $this->tempDir . '/var/updates/update_state.json';
        file_put_contents($stateFile, json_encode([
            'backup_path' => $this->tempDir . '/nonexistent_backup',
        ]));

        $this->assertFalse($this->updater->rollback());
    }

    // ── runMigrations (regression: demo-outage bug) ─────────────────
    //
    // The updater previously ran migrations via `new \App\Setup\SetupWizard(...)`
    // without loading the Composer autoloader — this always threw "Class
    // not found" and silently no-opped, so no auto-update ever applied its
    // migrations. runMigrations() must be self-contained (raw PDO only,
    // no /app or vendor dependency) per this class's own design docblock.

    public function testRunMigrationsAppliesPendingFilesAndTracksThem(): void
    {
        try {
            $pdo = new \PDO(
                sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', TEST_CONFIG['db']['host'], TEST_CONFIG['db']['port'], TEST_CONFIG['db']['name']),
                TEST_CONFIG['db']['user'],
                TEST_CONFIG['db']['password'],
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        $pdo->exec('DROP TABLE IF EXISTS `_migrations`');
        $pdo->exec('DROP TABLE IF EXISTS `updater_test_widgets`');

        mkdir($this->tempDir . '/app/migrations', 0755, true);
        file_put_contents(
            $this->tempDir . '/app/migrations/0001_widgets.sql',
            "CREATE TABLE `updater_test_widgets` (`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(50) NOT NULL);\n"
            . "INSERT INTO `updater_test_widgets` (`name`) VALUES ('seed');"
        );

        $method = new \ReflectionMethod(UpdateManager::class, 'runMigrations');
        $applied = $method->invoke($this->updater, $pdo);

        $this->assertSame(['0001_widgets.sql'], $applied);

        $tracked = $pdo->query("SELECT filename FROM `_migrations`")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['0001_widgets.sql'], $tracked);

        $rowCount = (int) $pdo->query("SELECT COUNT(*) FROM `updater_test_widgets`")->fetchColumn();
        $this->assertSame(1, $rowCount);

        $pdo->exec('DROP TABLE IF EXISTS `updater_test_widgets`');
        $pdo->exec('DROP TABLE IF EXISTS `_migrations`');
    }

    public function testRunMigrationsSkipsAlreadyAppliedFiles(): void
    {
        try {
            $pdo = new \PDO(
                sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', TEST_CONFIG['db']['host'], TEST_CONFIG['db']['port'], TEST_CONFIG['db']['name']),
                TEST_CONFIG['db']['user'],
                TEST_CONFIG['db']['password'],
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        $pdo->exec('DROP TABLE IF EXISTS `_migrations`');
        $pdo->exec("
            CREATE TABLE `_migrations` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `filename` VARCHAR(255) NOT NULL UNIQUE,
                `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $pdo->exec("INSERT INTO `_migrations` (`filename`) VALUES ('0001_widgets.sql')");

        mkdir($this->tempDir . '/app/migrations', 0755, true);
        // If this ran, it would fail (table already exists) — proves it was skipped
        file_put_contents(
            $this->tempDir . '/app/migrations/0001_widgets.sql',
            "CREATE TABLE `updater_test_widgets_dup` (`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY);"
        );

        $method = new \ReflectionMethod(UpdateManager::class, 'runMigrations');
        $applied = $method->invoke($this->updater, $pdo);

        $this->assertSame([], $applied);

        $pdo->exec('DROP TABLE IF EXISTS `_migrations`');
    }

    // ── syncPayload (regression: vendor/ never updated) ─────────────

    public function testSyncPayloadSwapsVendorDirectory(): void
    {
        $extractRoot = $this->tempDir . '/extract';
        mkdir($extractRoot . '/vendor', 0755, true);
        file_put_contents($extractRoot . '/vendor/marker.txt', 'new-vendor');

        // Simulate an existing (old) vendor/ at the install root
        mkdir($this->tempDir . '/vendor', 0755, true);
        file_put_contents($this->tempDir . '/vendor/marker.txt', 'old-vendor');

        $method = new \ReflectionMethod(UpdateManager::class, 'syncPayload');
        $method->invoke($this->updater, $extractRoot);

        $this->assertFileExists($this->tempDir . '/vendor/marker.txt');
        $this->assertSame('new-vendor', file_get_contents($this->tempDir . '/vendor/marker.txt'));
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}

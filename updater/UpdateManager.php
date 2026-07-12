<?php

declare(strict_types=1);

namespace Updater;

/**
 * ScoutKeeper -- Auto-Update Manager
 *
 * Standalone class that checks for, downloads, verifies, and applies
 * updates to the /app/ directory. Intentionally does NOT depend on the
 * Application singleton or any class inside /app/ because the updater
 * replaces /app/ during the update process.
 *
 * Update flow:
 *   1. Admin panel calls checkForUpdate() to query GitHub releases.
 *   2. On confirmation, downloadRelease() saves the zip locally.
 *   3. verifySignature() checks the Ed25519/RSA signature.
 *   4. run.php calls applyUpdate() to extract, swap, and migrate.
 *   5. On failure, rollback() restores the backup.
 */
class UpdateManager
{
    private string $rootPath;

    /** GitHub owner/repo for releases API. */
    private const GITHUB_REPO = 'quadninemt/scoutkeeper';

    /** Name of the public key file for signature verification. */
    private const PUBLIC_KEY_FILE = 'public-key.pem';

    /** Timeout in seconds for HTTP requests. */
    private const HTTP_TIMEOUT = 30;

    /** State file for tracking update progress. */
    private const STATE_FILE = 'var/updates/update_state.json';

    public function __construct(string $rootPath)
    {
        $this->rootPath = $rootPath;
    }

    // ── Public API ───────────────────────────────────────────────────

    /**
     * Check GitHub releases for a version newer than $currentVersion.
     *
     * @return array{version: string, download_url: string, release_notes: string}|null
     */
    public function checkForUpdate(string $currentVersion): ?array
    {
        $url = sprintf('https://api.github.com/repos/%s/releases/latest', self::GITHUB_REPO);

        $response = $this->httpGet($url, [
            'Accept: application/vnd.github+json',
            'User-Agent: ScoutKeeper-Updater/' . $currentVersion,
        ]);

        if ($response === null) {
            return null;
        }

        $release = json_decode($response, true);
        if (!is_array($release) || empty($release['tag_name'])) {
            return null;
        }

        $latestVersion = ltrim($release['tag_name'], 'vV');

        if (version_compare($latestVersion, $currentVersion, '<=')) {
            return null; // Already up to date
        }

        // Find the .zip asset
        $downloadUrl = null;
        $signatureUrl = null;
        foreach ($release['assets'] ?? [] as $asset) {
            $name = $asset['name'] ?? '';
            if (str_ends_with($name, '.zip') && !str_ends_with($name, '.sig.zip')) {
                $downloadUrl = $asset['browser_download_url'] ?? null;
            }
            if (str_ends_with($name, '.zip.sig')) {
                $signatureUrl = $asset['browser_download_url'] ?? null;
            }
        }

        if ($downloadUrl === null) {
            // Fall back to the source zip
            $downloadUrl = $release['zipball_url'] ?? null;
        }

        if ($downloadUrl === null) {
            return null;
        }

        return [
            'version' => $latestVersion,
            'download_url' => $downloadUrl,
            'signature_url' => $signatureUrl,
            'release_notes' => $release['body'] ?? '',
            'published_at' => $release['published_at'] ?? '',
        ];
    }

    /**
     * Download a release zip to a target path.
     */
    public function downloadRelease(string $url, string $targetPath): bool
    {
        $dir = dirname($targetPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $content = $this->httpGet($url, [
            'User-Agent: ScoutKeeper-Updater',
            'Accept: application/octet-stream',
        ]);

        if ($content === null || $content === '') {
            return false;
        }

        return file_put_contents($targetPath, $content) !== false;
    }

    /**
     * Verify the Ed25519/RSA signature of a downloaded zip.
     *
     * The signature file should contain the raw binary or base64-encoded signature.
     * Verification is done against the public key stored in update_public_key.pem.
     */
    public function verifySignature(string $zipPath, string $signaturePath): bool
    {
        $publicKeyFile = $this->rootPath . '/' . self::PUBLIC_KEY_FILE;

        if (!file_exists($publicKeyFile)) {
            // If no public key is configured, skip verification
            // (self-hosted users may not use signed releases)
            return true;
        }

        if (!file_exists($zipPath) || !file_exists($signaturePath)) {
            return false;
        }

        $publicKey = openssl_pkey_get_public(file_get_contents($publicKeyFile));
        if ($publicKey === false) {
            return false;
        }

        $data = file_get_contents($zipPath);
        $signature = file_get_contents($signaturePath);

        if ($data === false || $signature === false) {
            return false;
        }

        // Try raw binary first, then base64 decode
        $result = openssl_verify($data, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        if ($result !== 1) {
            // Try base64-decoded signature
            $decodedSig = base64_decode($signature, true);
            if ($decodedSig !== false) {
                $result = openssl_verify($data, $decodedSig, $publicKey, OPENSSL_ALGO_SHA256);
            }
        }

        return $result === 1;
    }

    /**
     * Apply an update from a downloaded zip file.
     *
     * 1. Back up current /app/ to /var/updates/app_backup_{version}/
     * 2. Extract the new /app/ from the zip
     * 3. Swap via rename() for atomicity
     * 4. Run new migrations
     * 5. Update app_version in settings table
     *
     * @return array{success: bool, steps_completed: string[], error: string|null}
     */
    public function applyUpdate(string $zipPath, ?string $newVersion = null): array
    {
        $steps = [];

        $config = $this->loadConfig();
        if ($config === null) {
            return ['success' => false, 'steps_completed' => $steps, 'error' => 'Cannot load config.php'];
        }

        $currentVersion = $this->getCurrentVersion($config);
        $backupDir = $this->rootPath . '/var/updates/app_backup_' . $currentVersion . '_' . date('Ymd_His');
        $extractDir = $this->rootPath . '/var/updates/extract_' . date('Ymd_His');
        $appDir = $this->rootPath . '/app';

        try {
            // Step 1: Backup current /app/
            if (!$this->copyDirectory($appDir, $backupDir)) {
                return ['success' => false, 'steps_completed' => $steps, 'error' => 'Failed to create backup of /app/'];
            }
            $steps[] = 'backup_created';
            $this->saveState('backup', $backupDir);

            // Step 2: Extract the zip
            $zip = new \ZipArchive();
            if ($zip->open($zipPath) !== true) {
                return ['success' => false, 'steps_completed' => $steps, 'error' => 'Failed to open update zip'];
            }

            @mkdir($extractDir, 0755, true);
            $zip->extractTo($extractDir);
            $zip->close();
            $steps[] = 'extracted';

            // Find the /app/ directory inside the extract
            $newAppDir = $this->findAppDirectory($extractDir);
            if ($newAppDir === null) {
                return ['success' => false, 'steps_completed' => $steps, 'error' => 'Could not find app/ directory in update package'];
            }

            // Step 3: Swap /app/ atomically via rename
            $tempOldApp = $appDir . '_old_' . date('Ymd_His');
            if (!@rename($appDir, $tempOldApp)) {
                return ['success' => false, 'steps_completed' => $steps, 'error' => 'Failed to move old /app/ aside'];
            }

            if (!@rename($newAppDir, $appDir)) {
                // Restore the old app
                @rename($tempOldApp, $appDir);
                return ['success' => false, 'steps_completed' => $steps, 'error' => 'Failed to move new /app/ into place'];
            }
            $steps[] = 'app_swapped';

            // Remove the temp old app directory
            $this->removeDirectory($tempOldApp);

            // Step 3b: Sync other top-level payload directories/files. These
            // live outside /app/ (lang, assets, cron, updater, root scripts)
            // and must also track the release, otherwise new translation keys,
            // JS/CSS assets, or updater fixes never reach the user.
            $extractRoot = dirname($newAppDir); // parent of the new app/
            $this->syncPayload($extractRoot);
            $steps[] = 'payload_synced';

            // Step 4: Run migrations
            try {
                $pdo = $this->createPdo($config['db']);
                $wizard = new \App\Setup\SetupWizard($this->rootPath);
                $applied = $wizard->runMigrations($pdo);
                $steps[] = 'migrations_run';
                if (!empty($applied)) {
                    $steps[] = 'migrations_applied: ' . implode(', ', $applied);
                }
            } catch (\Throwable $e) {
                $steps[] = 'migration_warning: ' . $e->getMessage();
                // Don't fail the whole update for migration issues
            }

            // Step 5: Update version everywhere
            if ($newVersion !== null) {
                // Update VERSION file (primary source of truth)
                @file_put_contents($this->rootPath . '/VERSION', $newVersion . "\n");

                // Update settings table (fallback source)
                try {
                    $pdo = $pdo ?? $this->createPdo($config['db']);
                    $stmt = $pdo->prepare(
                        "INSERT INTO `settings` (`key`, `value`, `group`)
                         VALUES ('app_version', :ver, 'general')
                         ON DUPLICATE KEY UPDATE `value` = :ver2"
                    );
                    $stmt->execute(['ver' => $newVersion, 'ver2' => $newVersion]);
                    $steps[] = 'version_updated: ' . $newVersion;
                } catch (\Throwable $e) {
                    $steps[] = 'version_update_warning: ' . $e->getMessage();
                }
            }

            // Step 6: Clear caches so new templates/translations take effect
            $this->clearCaches();
            $steps[] = 'caches_cleared';

            // Clean up extract directory
            $this->removeDirectory($extractDir);
            $steps[] = 'cleanup_done';

            $this->clearState();

            return ['success' => true, 'steps_completed' => $steps, 'error' => null];
        } catch (\Throwable $e) {
            return ['success' => false, 'steps_completed' => $steps, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get the current update state from the state file.
     */
    public function getUpdateState(): ?array
    {
        $stateFile = $this->rootPath . '/' . self::STATE_FILE;
        if (!file_exists($stateFile)) {
            return null;
        }

        $data = json_decode(file_get_contents($stateFile), true);
        return is_array($data) ? $data : null;
    }

    /**
     * Roll back to the previous /app/ from backup.
     */
    public function rollback(): bool
    {
        $state = $this->getUpdateState();
        if ($state === null || empty($state['backup_path'])) {
            return false;
        }

        $backupDir = $state['backup_path'];
        $appDir = $this->rootPath . '/app';

        if (!is_dir($backupDir)) {
            return false;
        }

        try {
            // Move current (broken) app aside
            $brokenDir = $appDir . '_broken_' . date('Ymd_His');
            if (is_dir($appDir)) {
                if (!@rename($appDir, $brokenDir)) {
                    return false;
                }
            }

            // Restore backup
            if (!@rename($backupDir, $appDir)) {
                // Try to restore the broken version
                @rename($brokenDir, $appDir);
                return false;
            }

            // Remove the broken copy
            if (is_dir($brokenDir)) {
                $this->removeDirectory($brokenDir);
            }

            $this->clearState();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get the current app version from config or settings.
     */
    public function getCurrentVersion(?array $config = null): string
    {
        // Primary source: VERSION file at project root
        $versionFile = $this->rootPath . '/VERSION';
        if (file_exists($versionFile)) {
            $version = trim(file_get_contents($versionFile));
            if ($version !== '') {
                return $version;
            }
        }

        // Fallback: settings table
        if ($config === null) {
            $config = $this->loadConfig();
        }
        if ($config === null) {
            return 'unknown';
        }

        try {
            $pdo = $this->createPdo($config['db']);
            $stmt = $pdo->prepare("SELECT `value` FROM `settings` WHERE `key` = 'app_version'");
            $stmt->execute();
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row && !empty($row['value'])) {
                return $row['value'];
            }
        } catch (\Throwable) {
            // Fall through
        }

        return 'unknown';
    }

    /**
     * Set maintenance mode on or off.
     */
    public function setMaintenanceMode(bool $enabled): void
    {
        $flagFile = $this->rootPath . '/var/maintenance.flag';

        if ($enabled) {
            $dir = dirname($flagFile);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            file_put_contents($flagFile, date('c'));
        } else {
            if (file_exists($flagFile)) {
                @unlink($flagFile);
            }
        }
    }

    /**
     * Record a downloaded zip path in state and generate a single-use token.
     *
     * Call this after downloadRelease() and verifySignature() succeed.
     * Returns the token to pass as ?token= when redirecting to run.php.
     */
    public function prepareDownload(string $zipPath, ?string $version = null): string
    {
        $this->saveState('zip_path', $zipPath);
        if ($version !== null) {
            $this->saveState('new_version', $version);
        }
        return $this->generateUpdateToken();
    }

    /**
     * Generate a single-use token for run.php.
     */
    public function generateUpdateToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->saveState('update_token', $token);
        $this->saveState('token_created_at', time());
        return $token;
    }

    /**
     * Verify a single-use update token.
     */
    public function verifyUpdateToken(string $token): bool
    {
        $state = $this->getUpdateState();
        if ($state === null || empty($state['update_token'])) {
            return false;
        }

        // Token must match and be less than 1 hour old
        $createdAt = (int) ($state['token_created_at'] ?? 0);
        if (time() - $createdAt > 3600) {
            return false;
        }

        return hash_equals($state['update_token'], $token);
    }

    // ── Private Helpers ──────────────────────────────────────────────

    /**
     * Perform an HTTP GET request using cURL or file_get_contents.
     */
    private function httpGet(string $url, array $headers = []): ?string
    {
        // Prefer cURL if available
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => self::HTTP_TIMEOUT,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($httpCode >= 200 && $httpCode < 300 && is_string($response)) {
                return $response;
            }
            return null;
        }

        // Fallback to file_get_contents with stream context
        $headerString = implode("\r\n", $headers);
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => $headerString,
                'timeout' => self::HTTP_TIMEOUT,
                'follow_location' => true,
                'max_redirects' => 5,
            ],
            'ssl' => [
                'verify_peer' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);
        return $result !== false ? $result : null;
    }

    /**
     * Load config.php from the project root.
     */
    private function loadConfig(): ?array
    {
        $configPath = $this->rootPath . '/config/config.php';
        if (!file_exists($configPath)) {
            return null;
        }

        $config = require $configPath;
        return is_array($config) ? $config : null;
    }

    /**
     * Create a PDO connection from a database config array.
     */
    private function createPdo(array $dbConfig): \PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $dbConfig['host'],
            $dbConfig['port'] ?? '3306',
            $dbConfig['name']
        );

        return new \PDO($dsn, $dbConfig['user'], $dbConfig['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /**
     * Sync non-/app/ payload from the extracted release into the install root.
     *
     * Shipped directories (lang, assets, cron, updater) are swapped via rename
     * for a clean replace. Shipped root files (VERSION, index.php, .htaccess,
     * composer.json, composer.lock) are copied over in place. User/runtime
     * directories — config/, data/, var/, backups/, vendor/, tests/ — are
     * never touched. Anything absent from the extract is left alone.
     */
    private function syncPayload(string $extractRoot): void
    {
        $dirs = ['lang', 'assets', 'cron', 'updater'];
        foreach ($dirs as $d) {
            $src = $extractRoot . '/' . $d;
            if (!is_dir($src)) {
                continue;
            }
            $dst = $this->rootPath . '/' . $d;
            $tempOld = $dst . '_old_' . date('Ymd_His');
            if (is_dir($dst) && !@rename($dst, $tempOld)) {
                continue;
            }
            if (!@rename($src, $dst)) {
                if (is_dir($tempOld)) {
                    @rename($tempOld, $dst);
                }
                continue;
            }
            if (is_dir($tempOld)) {
                $this->removeDirectory($tempOld);
            }
        }

        $files = ['VERSION', 'index.php', '.htaccess', 'composer.json', 'composer.lock'];
        foreach ($files as $f) {
            $src = $extractRoot . '/' . $f;
            if (is_file($src)) {
                @copy($src, $this->rootPath . '/' . $f);
            }
        }
    }

    /**
     * Clear compiled Twig templates and i18n caches so newly-shipped templates
     * and translations take effect on the next request. Safe to call any time;
     * caches are rebuilt on demand.
     *
     * @return array{twig_cleared:bool, i18n_cleared:int}
     */
    public function clearCaches(): array
    {
        $twigDir = $this->rootPath . '/var/cache/twig';
        $twigCleared = false;
        if (is_dir($twigDir)) {
            $twigCleared = $this->removeDirectory($twigDir);
            @mkdir($twigDir, 0755, true);
        }

        $i18nCleared = 0;
        $cacheDir = $this->rootPath . '/var/cache';
        if (is_dir($cacheDir)) {
            foreach ((array) glob($cacheDir . '/i18n_*.json') as $file) {
                if (@unlink($file)) {
                    $i18nCleared++;
                }
            }
        }

        return ['twig_cleared' => $twigCleared, 'i18n_cleared' => $i18nCleared];
    }

    /**
     * Recursively copy a directory.
     */
    private function copyDirectory(string $src, string $dst): bool
    {
        if (!is_dir($src)) {
            return false;
        }

        if (!@mkdir($dst, 0755, true) && !is_dir($dst)) {
            return false;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $target = $dst . '/' . $iterator->getSubPathname();
            if ($item->isDir()) {
                if (!@mkdir($target, 0755, true) && !is_dir($target)) {
                    return false;
                }
            } else {
                if (!@copy($item->getPathname(), $target)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Recursively remove a directory.
     */
    private function removeDirectory(string $dir): void
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

    /**
     * Find the /app/ directory inside an extracted update archive.
     *
     * The zip may contain a top-level directory (e.g. scoutkeeper-1.2.0/app/)
     * or the app/ directory directly.
     */
    private function findAppDirectory(string $extractDir): ?string
    {
        // Direct app/ directory
        if (is_dir($extractDir . '/app')) {
            return $extractDir . '/app';
        }

        // One level deep (e.g. scoutkeeper-1.2.0/app/)
        $subdirs = glob($extractDir . '/*/app');
        if (!empty($subdirs) && is_dir($subdirs[0])) {
            return $subdirs[0];
        }

        return null;
    }

    /**
     * Save a key-value pair to the update state file.
     */
    private function saveState(string $key, mixed $value): void
    {
        $stateFile = $this->rootPath . '/' . self::STATE_FILE;
        $dir = dirname($stateFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $state = $this->getUpdateState() ?? [];
        $state[$key] = $value;
        $state['updated_at'] = gmdate('c');

        file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * Clear the update state file.
     */
    private function clearState(): void
    {
        $stateFile = $this->rootPath . '/' . self::STATE_FILE;
        if (file_exists($stateFile)) {
            @unlink($stateFile);
        }
    }
}

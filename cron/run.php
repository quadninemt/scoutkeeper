<?php

/**
 * ScoutKeeper — Cron Entry Point
 *
 * Dispatches registered cron task handlers in sequence.
 * Can be called via a system cron job (CLI) or via HTTP with a secret token.
 *
 * CLI usage (cPanel cron):
 *   /usr/bin/php /path/to/public_html/cron/run.php
 *
 * HTTP usage (fallback):
 *   GET /cron/run.php?secret=YOUR_CRON_SECRET
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));

$isCli = php_sapi_name() === 'cli';

// Load configuration
$configPath = ROOT_PATH . '/config/config.php';
if (!file_exists($configPath)) {
    if ($isCli) {
        echo "Error: config.php not found.\n";
    }
    exit(1);
}

$config = require $configPath;

// Authenticate: CLI is always allowed; HTTP requires the secret token
if (!$isCli) {
    $secret = $config['cron']['secret'] ?? '';
    $provided = $_GET['secret'] ?? '';
    if ($secret === '' || !hash_equals($secret, $provided)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit(1);
    }
}

// Bootstrap the application
require ROOT_PATH . '/vendor/autoload.php';

error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
ini_set('display_errors', '0');
date_default_timezone_set($config['app']['timezone'] ?? 'UTC');

// Bootstrap the application core (no session/routing) and dispatch cron handlers
\App\Core\Application::init($config);
$app = \App\Core\Application::getInstance();

$handlerResults = [];
$overallStatus = 'ok';

foreach ($app->getModuleRegistry()->getCronHandlers() as $handler) {
    $handlerName = get_class($handler);
    try {
        $handler->execute($app);
        $handlerResults[$handlerName] = 'ok';
    } catch (\Throwable $e) {
        $handlerResults[$handlerName] = 'error: ' . $e->getMessage();
        $overallStatus = 'error';
        \App\Core\Logger::error('Cron handler failed', [
            'handler' => $handlerName,
            'error' => $e->getMessage(),
        ]);
    }
}

$logEntry = [
    'timestamp' => gmdate('c'),
    'mode' => $isCli ? 'cli' : 'http',
    'status' => $overallStatus,
    'handlers' => $handlerResults,
];

// Update last run timestamp
file_put_contents(ROOT_PATH . '/var/cache/cron_last_run.txt', (string) time());

// Log the run
$logFile = ROOT_PATH . '/var/logs/cron.json';
$existing = [];
if (file_exists($logFile)) {
    $existing = json_decode(file_get_contents($logFile), true) ?? [];
}
$existing[] = $logEntry;
// Keep last 100 entries
if (count($existing) > 100) {
    $existing = array_slice($existing, -100);
}
file_put_contents($logFile, json_encode($existing, JSON_PRETTY_PRINT));

if ($isCli) {
    echo "Cron run completed.\n";
} else {
    header('Content-Type: application/json');
    echo json_encode($logEntry);
}

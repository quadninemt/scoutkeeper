<?php
declare(strict_types=1);

// Dev tool — CLI only, never web-accessible
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require __DIR__ . '/../vendor/autoload.php';
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}
$cfg = require ROOT_PATH . '/config/config.php';
$db = new App\Core\Database($cfg['db']);
$m = new App\Core\Migration($db);
$m->ensureTable();

$pending = $m->getPending();
if (!$pending) {
    echo "No pending migrations.\n";
    exit(0);
}
echo "Pending: " . implode(", ", $pending) . "\n";
$applied = $m->migrate();
echo "Applied: " . implode(", ", $applied) . "\n";
echo "Done.\n";

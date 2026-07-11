<?php

/**
 * ScoutKeeper — Entry Point
 *
 * This file is the single entry point for all web requests.
 * It is part of the bootstrap tier (Tier 0) and is never auto-updated.
 */

declare(strict_types=1);

define('ROOT_PATH', __DIR__);

// PHP built-in dev server: serve static files directly
if (php_sapi_name() === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $file = ROOT_PATH . $path;
    if ($path !== '/' && is_file($file)) {
        return false; // Let the built-in server handle static files
    }
}

// Check maintenance mode (but allow updater access)
if (file_exists(ROOT_PATH . '/var/maintenance.flag')) {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($uri !== '/setup' && !str_starts_with($uri, '/updater/')) {
        http_response_code(503);
        if (file_exists(ROOT_PATH . '/app/templates/errors/maintenance.html.twig')) {
            // Plain HTML despite the .twig extension — served directly during maintenance
            include ROOT_PATH . '/app/templates/errors/maintenance.html.twig';
        } else {
            echo '<!DOCTYPE html><html><head><title>Maintenance</title></head><body><h1>System Maintenance</h1><p>We are performing scheduled maintenance. Please try again shortly.</p></body></html>';
        }
        exit;
    }
}

// Setup wizard — runs before bootstrap if config does not exist or /setup is requested
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$configExists = file_exists(ROOT_PATH . '/config/config.php');

if (!$configExists || $requestUri === '/setup') {
    // The setup wizard is self-contained (no Twig, no module registry)
    if (!file_exists(ROOT_PATH . '/vendor/autoload.php')) {
        http_response_code(500);
        echo '<!DOCTYPE html><html><head><title>Dependencies Missing</title>'
           . '<style>body{font-family:system-ui,sans-serif;max-width:600px;margin:80px auto;padding:0 20px;color:#333}'
           . 'h1{color:#dc3545}code{background:#f5f5f5;padding:2px 6px;border-radius:3px;font-size:0.9em}'
           . '.box{background:#f8f9fa;border:1px solid #dee2e6;border-radius:8px;padding:20px;margin:20px 0}</style></head>'
           . '<body><h1>Dependencies Not Installed</h1>'
           . '<p>ScoutKeeper requires Composer dependencies to run.</p>'
           . '<div class="box"><p>Run this command in the site root directory:</p>'
           . '<pre><code>composer install --no-dev</code></pre></div>'
           . '<p>If Composer is not installed, see <a href="https://getcomposer.org">getcomposer.org</a>.</p>'
           . '</body></html>';
        exit;
    }
    require ROOT_PATH . '/vendor/autoload.php';

    session_start();

    // If config already exists and someone hits /setup, block access
    if ($configExists) {
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><title>Setup Unavailable</title></head><body>'
           . '<h1>Setup Already Complete</h1>'
           . '<p>ScoutKeeper is already configured. Delete <code>config/config.php</code> to re-run setup.</p>'
           . '<p><a href="/login">Go to login</a></p></body></html>';
        exit;
    }

    // If config doesn't exist and user hit a non-setup URL, redirect to /setup
    if ($requestUri !== '/setup') {
        header('Location: /setup');
        exit;
    }

    $wizard = new \App\Setup\SetupWizard(ROOT_PATH);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $step = (int) ($_POST['step'] ?? $wizard->getCurrentStep());
        $result = $wizard->processStep($step, $_POST);

        if ($result['success']) {
            // Special case: final step success means config was just written
            if ($step === 8) {
                echo $wizard->renderStep(8, ['justFinished' => true]);
                exit;
            }
            // Redirect to next step (PRG pattern)
            header('Location: /setup?step=' . $result['next_step']);
            exit;
        }

        // Validation failed — re-render the same step with errors
        echo $wizard->renderStep($step, ['errors' => $result['errors']]);
        exit;
    }

    // GET — show the requested or current step
    $maxReached = (int) ($_SESSION['setup_step'] ?? 1);
    $step = isset($_GET['step']) ? (int) $_GET['step'] : $maxReached;
    // Allow going back to any previous step, but not forward past the highest reached
    $step = max(1, min($step, $maxReached));
    // Skip steps that the current install-type choice has made irrelevant
    // (e.g. demo installs skip the organisation step).
    $resolved = $wizard->resolveVisibleStep($step);
    if ($resolved !== $step) {
        header('Location: /setup?step=' . $resolved);
        exit;
    }
    echo $wizard->renderStep($step);
    exit;
}

// Bootstrap the application
require ROOT_PATH . '/app/bootstrap.php';

// Run
App\Core\Application::run();

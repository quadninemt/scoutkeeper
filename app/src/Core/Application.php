<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Application singleton — the central orchestrator for ScoutKeeper.
 *
 * Holds references to all core services (database, router, template engine,
 * session, i18n, module registry) and drives the request lifecycle.
 */
class Application
{
    private static ?Application $instance = null;

    private array $config;
    private ?Database $db = null;
    private ?Router $router = null;
    private ?TwigRenderer $twig = null;
    private ?Session $session = null;
    private ?I18n $i18n = null;
    private ?ModuleRegistry $moduleRegistry = null;
    private ?Request $request = null;
    private ?ErrorHandler $errorHandler = null;
    private ?PermissionResolver $permissionResolver = null;

    private function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Initialise the Application singleton with configuration.
     * Called once during bootstrap.
     */
    public static function init(array $config): void
    {
        if (self::$instance !== null) {
            return;
        }
        self::$instance = new self($config);
    }

    /**
     * Get the Application singleton instance.
     *
     * @throws \RuntimeException if not yet initialised
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('Application not initialised. Call Application::init() first.');
        }
        return self::$instance;
    }

    /**
     * Run the full request lifecycle: session → modules → route → render.
     */
    public static function run(): void
    {
        $app = self::getInstance();
        $requestStart = microtime(true);

        // Register error handler
        $app->errorHandler = new ErrorHandler($app->config);
        $app->errorHandler->register();

        // Create request
        $app->request = Request::fromGlobals();

        // Start session
        $app->session = new Session($app->config);
        $app->session->start();

        // Initialise database. Merge monitoring.slow_query_threshold_ms into the
        // db config so Database can honour the configured threshold.
        $dbConfig = $app->config['db'];
        if (isset($app->config['monitoring']['slow_query_threshold_ms'])) {
            $dbConfig['slow_query_threshold_ms'] = $app->config['monitoring']['slow_query_threshold_ms'];
        }
        $app->db = new Database($dbConfig);

        // Initialise i18n. Resolution order:
        //   1. Session — explicit user choice via the topbar switcher
        //   2. languages.is_default in the DB — admin-configured installation default
        //   3. config['app']['language'] — config file fallback
        //   4. 'en' — last-resort hard fallback
        $language = $app->session->get('language');
        if ($language === null || $language === '') {
            try {
                $dbDefault = $app->db->fetchColumn(
                    "SELECT code FROM languages WHERE is_default = 1 AND is_active = 1 LIMIT 1"
                );
                if (is_string($dbDefault) && $dbDefault !== '') {
                    $language = $dbDefault;
                }
            } catch (\PDOException) {
                // languages table may not exist yet during setup
            }
        }
        $language = $language ?: ($app->config['app']['language'] ?? 'en');
        $app->i18n = new I18n(ROOT_PATH . '/lang', $app->db, $language);

        // Initialise Twig
        $app->twig = new TwigRenderer($app);

        // Initialise module registry and load modules
        $app->moduleRegistry = new ModuleRegistry();
        $app->moduleRegistry->loadModules(ROOT_PATH . '/app/modules');

        // Initialise router and register routes
        $app->router = new Router();
        $app->moduleRegistry->registerRoutes($app->router);

        // CSRF validation for state-changing requests
        if ($app->request->isStateChanging()) {
            $csrf = new Csrf($app->session);
            $csrfToken = $app->request->getParam('_csrf_token')
                ?? $app->request->getParam('_csrf')
                ?? $app->request->getHeader('X-CSRF-Token')
                ?? '';
            if (!$csrf->validateToken((string) $csrfToken)) {
                $app->sendResponse(new Response(403, 'CSRF token validation failed'));
                return;
            }
        }

        // Dispatch the route
        $response = $app->router->dispatch($app->request);
        $app->sendResponse($response);

        // Record per-request profile for the debugging tool
        $app->logRequestProfile($requestStart, $response);

        // Pseudo-cron fallback: run pending cron tasks after response
        $app->runPseudoCron();
    }

    /**
     * Append a compact request profile to var/logs/requests.json for any
     * request that is slow or runs unusually many queries.
     */
    private function logRequestProfile(float $startedAt, Response $response): void
    {
        $wallMs = (microtime(true) - $startedAt) * 1000;
        $profile = $this->db ? $this->db->getProfile() : ['count' => 0, 'total_ms' => 0.0, 'samples' => []];

        $wallThreshold  = (float) ($this->config['monitoring']['slow_request_threshold_ms'] ?? 300);
        $countThreshold = (int)   ($this->config['monitoring']['slow_request_query_count'] ?? 50);

        if ($wallMs < $wallThreshold && $profile['count'] < $countThreshold) {
            return;
        }

        // Aggregate samples by normalised SQL to surface N+1 patterns
        $grouped = [];
        foreach ($profile['samples'] as $s) {
            $key = preg_replace('/\s+/', ' ', trim((string) $s['sql']));
            $key = substr($key ?? '', 0, 200);
            if (!isset($grouped[$key])) {
                $grouped[$key] = ['sql' => $key, 'count' => 0, 'total_ms' => 0.0, 'max_ms' => 0.0];
            }
            $grouped[$key]['count']++;
            $grouped[$key]['total_ms'] += $s['ms'];
            if ($s['ms'] > $grouped[$key]['max_ms']) {
                $grouped[$key]['max_ms'] = $s['ms'];
            }
        }
        usort($grouped, static fn($a, $b) => $b['count'] <=> $a['count'] ?: $b['total_ms'] <=> $a['total_ms']);
        $top = array_slice(array_map(static fn($g) => [
            'sql'      => $g['sql'],
            'count'    => $g['count'],
            'total_ms' => round($g['total_ms'], 2),
            'max_ms'   => round($g['max_ms'], 2),
        ], $grouped), 0, 10);

        $entry = [
            'timestamp'   => gmdate('c'),
            'method'      => $this->request?->getMethod() ?? '',
            'uri'         => $this->request ? ($_SERVER['REQUEST_URI'] ?? '') : '',
            'status'      => $response->getStatusCode(),
            'wall_ms'     => round($wallMs, 2),
            'query_count' => $profile['count'],
            'query_ms'    => $profile['total_ms'],
            'user_id'     => $_SESSION['user']['id'] ?? null,
            'top_queries' => $top,
        ];

        $file = ROOT_PATH . '/var/logs/requests.json';
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            return;
        }

        $existing = [];
        if (file_exists($file)) {
            $existing = json_decode((string) file_get_contents($file), true) ?: [];
        }
        $existing[] = $entry;
        if (count($existing) > 500) {
            $existing = array_slice($existing, -500);
        }
        @file_put_contents($file, json_encode($existing, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX);
    }

    /**
     * Send the HTTP response to the client.
     */
    private function sendResponse(Response $response): void
    {
        http_response_code($response->getStatusCode());
        foreach ($response->getHeaders() as $name => $value) {
            header("$name: $value");
        }
        if ($response->isFileResponse()) {
            readfile((string) $response->getFilePath());
        } else {
            echo $response->getBody();
        }
    }

    /**
     * Run pending cron tasks after the response has been sent.
     * Only activates when no real cron is configured and enough time has elapsed.
     */
    private function runPseudoCron(): void
    {
        // Only if fastcgi_finish_request is available (keeps response fast)
        if (!function_exists('fastcgi_finish_request')) {
            return;
        }

        $lastRunFile = ROOT_PATH . '/var/cache/cron_last_run.txt';
        $interval = (int) ($this->config['cron']['email_interval_seconds'] ?? 60);

        if (file_exists($lastRunFile)) {
            $lastRun = (int) file_get_contents($lastRunFile);
            if (time() - $lastRun < $interval) {
                return;
            }
        }

        fastcgi_finish_request();

        // Execute cron handlers from module registry
        file_put_contents($lastRunFile, (string) time());
        foreach ($this->moduleRegistry->getCronHandlers() as $handler) {
            try {
                $handler->execute($this);
            } catch (\Throwable $e) {
                Logger::error('Pseudo-cron handler failed', [
                    'handler' => get_class($handler),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    // --- Accessors ---

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getConfigValue(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $this->config;
        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }
        return $value;
    }

    public function getDb(): Database
    {
        if ($this->db === null) {
            $dbConfig = $this->config['db'];
            if (isset($this->config['monitoring']['slow_query_threshold_ms'])) {
                $dbConfig['slow_query_threshold_ms'] = $this->config['monitoring']['slow_query_threshold_ms'];
            }
            $this->db = new Database($dbConfig);
        }
        return $this->db;
    }

    public function getRouter(): Router
    {
        return $this->router;
    }

    public function getTwig(): TwigRenderer
    {
        return $this->twig;
    }

    public function getSession(): Session
    {
        return $this->session;
    }

    public function getI18n(): I18n
    {
        return $this->i18n;
    }

    public function getModuleRegistry(): ModuleRegistry
    {
        if ($this->moduleRegistry === null) {
            $this->moduleRegistry = new ModuleRegistry();
            $this->moduleRegistry->loadModules(ROOT_PATH . '/app/modules');
        }
        return $this->moduleRegistry;
    }

    public function getRequest(): Request
    {
        return $this->request;
    }

    /**
     * Get the permission resolver, initialising it lazily for the current user.
     */
    public function getPermissionResolver(): PermissionResolver
    {
        if ($this->permissionResolver === null) {
            $this->permissionResolver = new PermissionResolver($this->db, $this->session);

            // Load permissions for the current user if authenticated
            $user = $this->session->getUser();
            if ($user !== null) {
                $this->permissionResolver->loadForUser((int) $user['id']);
            }
        }

        return $this->permissionResolver;
    }

    /**
     * Reset the singleton — used only in tests.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}

<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Session manager with secure defaults.
 *
 * Handles session lifecycle, flash messages, authentication state,
 * and timeout checking. Uses file-based sessions stored in /var/sessions/.
 */
class Session
{
    private array $config;
    private bool $started = false;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Start the session with secure settings.
     */
    public function start(): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }

        $sessionPath = ROOT_PATH . '/var/sessions';
        if (!is_dir($sessionPath)) {
            mkdir($sessionPath, 0700, true);
        }

        ini_set('session.save_path', $sessionPath);
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', (string) ($this->config['security']['session_timeout'] ?? 7200));

        // Set secure cookie flag if using HTTPS — directly or behind a
        // TLS-terminating proxy (X-Forwarded-Proto)
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        if ($isHttps) {
            ini_set('session.cookie_secure', '1');
        }

        session_start();
        $this->started = true;

        // Check for session timeout
        $this->checkTimeout();
    }

    /**
     * Get a session value.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Set a session value.
     */
    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    /**
     * Remove a session value.
     */
    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Destroy the session completely.
     */
    public function destroy(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
        $this->started = false;
    }

    /**
     * Set a flash message (available only for the next request).
     */
    public function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][$type][] = $message;
    }

    /**
     * Get and clear flash messages.
     *
     * @return array<string, array<string>> Messages grouped by type
     */
    public function getFlash(): array
    {
        $flash = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $flash;
    }

    /**
     * Pop a single flash bucket without consuming the rest of the stream.
     * Returns the stored messages and clears that key only.
     *
     * @return array<int, string>
     */
    public function takeFlashOfType(string $type): array
    {
        $messages = $_SESSION['_flash'][$type] ?? [];
        unset($_SESSION['_flash'][$type]);
        if (isset($_SESSION['_flash']) && $_SESSION['_flash'] === []) {
            unset($_SESSION['_flash']);
        }
        return $messages;
    }

    /**
     * Check if the user is authenticated.
     */
    public function isAuthenticated(): bool
    {
        return isset($_SESSION['user']) && isset($_SESSION['user']['id']);
    }

    /**
     * Get the current authenticated user data.
     */
    public function getUser(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    /**
     * Set the authenticated user data.
     */
    public function setUser(array $user): void
    {
        $_SESSION['user'] = $user;
        $_SESSION['_last_activity'] = time();
        $this->regenerate();
    }

    /**
     * Regenerate the session ID (e.g. after login).
     */
    public function regenerate(): void
    {
        if ($this->started && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * Check for session timeout and destroy if expired.
     */
    private function checkTimeout(): void
    {
        $timeout = (int) ($this->config['security']['session_timeout'] ?? 7200);
        $lastActivity = $_SESSION['_last_activity'] ?? null;

        if ($lastActivity !== null && (time() - $lastActivity) > $timeout) {
            $this->destroy();
            $this->start();
            return;
        }

        if ($this->isAuthenticated()) {
            $_SESSION['_last_activity'] = time();
        }
    }
}

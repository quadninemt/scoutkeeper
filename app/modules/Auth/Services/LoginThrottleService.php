<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Core\Database;
use App\Core\Logger;

/**
 * IP-based login throttling.
 *
 * Complements the per-account lockout in AuthService: an attacker probing
 * many different accounts from one address is slowed down regardless of
 * which accounts exist, and cannot lock out arbitrary known accounts
 * without also throttling themselves.
 */
class LoginThrottleService
{
    /** Failed attempts allowed per IP within the window */
    private const MAX_ATTEMPTS_PER_IP = 20;

    /** Sliding window in minutes */
    private const WINDOW_MINUTES = 15;

    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Whether login processing should be refused for this IP.
     */
    public function isThrottled(string $ip): bool
    {
        $windowStart = gmdate('Y-m-d H:i:s', time() - self::WINDOW_MINUTES * 60);

        $count = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM login_attempts
             WHERE ip_address = :ip AND attempted_at >= :since",
            ['ip' => $ip, 'since' => $windowStart]
        );

        if ($count >= self::MAX_ATTEMPTS_PER_IP) {
            Logger::warning('Login throttled for IP', ['ip' => $ip, 'attempts' => $count]);
            return true;
        }

        return false;
    }

    /**
     * Record a failed login attempt for this IP and prune old entries.
     */
    public function recordFailure(string $ip): void
    {
        $this->db->insert('login_attempts', ['ip_address' => $ip]);

        // Opportunistic cleanup — the table stays small without a cron task
        $this->db->query(
            "DELETE FROM login_attempts WHERE attempted_at < :cutoff",
            ['cutoff' => gmdate('Y-m-d H:i:s', time() - 86400)]
        );
    }
}

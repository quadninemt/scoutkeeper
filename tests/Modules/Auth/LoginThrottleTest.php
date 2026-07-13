<?php

declare(strict_types=1);

namespace Tests\Modules\Auth;

use App\Core\Database;
use App\Modules\Auth\Services\LoginThrottleService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for IP-based login throttling.
 */
class LoginThrottleTest extends TestCase
{
    private Database $db;
    private LoginThrottleService $service;

    protected function setUp(): void
    {
        try {
            $this->db = new Database(TEST_CONFIG['db']);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        $this->db->query("DROP TABLE IF EXISTS `login_attempts`");
        $this->db->query("
            CREATE TABLE `login_attempts` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `ip_address` VARCHAR(45) NOT NULL,
                `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_login_attempts_ip_time` (`ip_address`, `attempted_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->service = new LoginThrottleService($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->query("DROP TABLE IF EXISTS `login_attempts`");
    }

    public function testNotThrottledInitially(): void
    {
        $this->assertFalse($this->service->isThrottled('198.51.100.1'));
    }

    /**
     * Regression for the demo-outage incident: if the login_attempts table
     * is missing (e.g. a migration hasn't run after an update), throttling
     * must fail open rather than 500 the login page.
     */
    public function testIsThrottledFailsOpenWhenTableMissing(): void
    {
        $this->db->query("DROP TABLE IF EXISTS `login_attempts`");

        $this->assertFalse($this->service->isThrottled('198.51.100.1'));
    }

    public function testRecordFailureSwallowsErrorWhenTableMissing(): void
    {
        $this->db->query("DROP TABLE IF EXISTS `login_attempts`");

        // Must not throw
        $this->service->recordFailure('198.51.100.1');
        $this->assertTrue(true);
    }

    public function testNotThrottledBelowLimit(): void
    {
        for ($i = 0; $i < 19; $i++) {
            $this->service->recordFailure('198.51.100.1');
        }
        $this->assertFalse($this->service->isThrottled('198.51.100.1'));
    }

    public function testThrottledAtLimit(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->service->recordFailure('198.51.100.1');
        }
        $this->assertTrue($this->service->isThrottled('198.51.100.1'));
    }

    public function testThrottleIsPerIp(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->service->recordFailure('198.51.100.1');
        }
        $this->assertTrue($this->service->isThrottled('198.51.100.1'));
        $this->assertFalse($this->service->isThrottled('198.51.100.2'));
    }

    public function testOldAttemptsExpireFromWindow(): void
    {
        // Insert attempts older than the 15-minute window
        for ($i = 0; $i < 25; $i++) {
            $this->db->insert('login_attempts', [
                'ip_address' => '198.51.100.1',
                'attempted_at' => gmdate('Y-m-d H:i:s', time() - 3600),
            ]);
        }
        $this->assertFalse($this->service->isThrottled('198.51.100.1'));
    }

    public function testRecordFailurePrunesOldEntries(): void
    {
        $this->db->insert('login_attempts', [
            'ip_address' => '198.51.100.9',
            'attempted_at' => gmdate('Y-m-d H:i:s', time() - 90000),
        ]);

        $this->service->recordFailure('198.51.100.1');

        $old = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM login_attempts WHERE ip_address = '198.51.100.9'"
        );
        $this->assertSame(0, $old);
    }
}

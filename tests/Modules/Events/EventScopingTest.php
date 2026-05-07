<?php

declare(strict_types=1);

namespace Tests\Modules\Events;

use App\Core\Database;
use App\Modules\Events\Services\EventService;
use PHPUnit\Framework\TestCase;

class EventScopingTest extends TestCase
{
    private Database $db;
    private int $nodeA;
    private int $nodeB;

    protected function setUp(): void
    {
        try {
            $this->db = new Database(TEST_CONFIG['db']);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
        foreach (['events', 'users', 'org_nodes'] as $t) {
            $this->db->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");

        $this->db->query("CREATE TABLE `users` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `email` VARCHAR(255) NOT NULL UNIQUE,
            `password_hash` VARCHAR(255) NOT NULL DEFAULT '',
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `org_nodes` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(200) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `events` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `title` VARCHAR(200) NOT NULL,
            `description` TEXT NULL,
            `location` VARCHAR(200) NULL,
            `start_date` DATETIME NOT NULL,
            `end_date` DATETIME NULL,
            `all_day` TINYINT(1) DEFAULT 0,
            `is_published` TINYINT(1) DEFAULT 1,
            `node_scope_id` INT UNSIGNED NULL,
            `created_by` INT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->nodeA = $this->db->insert('org_nodes', ['name' => 'A']);
        $this->nodeB = $this->db->insert('org_nodes', ['name' => 'B']);

        $this->db->insert('events', ['title' => 'Org',  'start_date' => '2099-01-01 10:00:00', 'node_scope_id' => null]);
        $this->db->insert('events', ['title' => 'A',    'start_date' => '2099-01-01 10:00:00', 'node_scope_id' => $this->nodeA]);
        $this->db->insert('events', ['title' => 'B',    'start_date' => '2099-01-01 10:00:00', 'node_scope_id' => $this->nodeB]);
    }

    public function testGetAllScopedToAIncludesOrg(): void
    {
        $svc = new EventService($this->db);
        $titles = array_column($svc->getAll(1, 20, null, null, [$this->nodeA])['items'], 'title');
        sort($titles);
        $this->assertSame(['A', 'Org'], $titles);
    }

    public function testGetAllScopedExcludesOtherNodes(): void
    {
        $svc = new EventService($this->db);
        $titles = array_column($svc->getAll(1, 20, null, null, [$this->nodeA])['items'], 'title');
        $this->assertNotContains('B', $titles);
    }

    public function testGetAllUnscopedReturnsAll(): void
    {
        $svc = new EventService($this->db);
        $titles = array_column($svc->getAll(1, 20)['items'], 'title');
        $this->assertCount(3, $titles);
    }
}

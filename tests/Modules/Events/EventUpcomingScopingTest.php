<?php

declare(strict_types=1);

namespace Tests\Modules\Events;

use App\Core\Database;
use App\Modules\Events\Services\EventService;
use PHPUnit\Framework\TestCase;

/**
 * Node-scoping tests for EventService::getUpcoming and the empty-array
 * ("global events only") scope branch of getForDateRange / getForMonth.
 *
 * Complements EventScopingTest, which covers getAll, getForDateRange and
 * getForMonth with null and non-empty node ID lists but does not cover
 * getUpcoming scoping at all, nor the [] (global-only) branch.
 */
class EventUpcomingScopingTest extends TestCase
{
    private Database $db;
    private EventService $svc;
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
            `email` VARCHAR(255) NOT NULL UNIQUE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `org_nodes` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(200) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `events` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `title` VARCHAR(300) NOT NULL,
            `description` TEXT NULL,
            `location` VARCHAR(300) NULL,
            `start_date` DATETIME NOT NULL,
            `end_date` DATETIME NULL,
            `all_day` TINYINT(1) NOT NULL DEFAULT 0,
            `node_scope_id` INT UNSIGNED NULL,
            `created_by` INT UNSIGNED NULL,
            `is_published` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->nodeA = $this->db->insert('org_nodes', ['name' => 'Node A']);
        $this->nodeB = $this->db->insert('org_nodes', ['name' => 'Node B']);

        // Future, published events: one global, one per node
        $this->insertEvent('Org', '2099-01-01 10:00:00', null, 1);
        $this->insertEvent('A', '2099-01-02 10:00:00', $this->nodeA, 1);
        $this->insertEvent('B', '2099-01-03 10:00:00', $this->nodeB, 1);

        $this->svc = new EventService($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
            foreach (['events', 'users', 'org_nodes'] as $t) {
                $this->db->query("DROP TABLE IF EXISTS `{$t}`");
            }
            $this->db->query("SET FOREIGN_KEY_CHECKS = 1");
        }
    }

    private function insertEvent(
        string $title,
        string $startDate,
        ?int $nodeScopeId,
        int $isPublished
    ): int {
        return $this->db->insert('events', [
            'title' => $title,
            'start_date' => $startDate,
            'node_scope_id' => $nodeScopeId,
            'is_published' => $isPublished,
        ]);
    }

    // ── getUpcoming node scoping ──────────────────────────────────────────

    public function testGetUpcomingWithNullNodeIdsReturnsAllPublished(): void
    {
        $titles = array_column($this->svc->getUpcoming(), 'title');
        sort($titles);
        $this->assertSame(['A', 'B', 'Org'], $titles);
    }

    public function testGetUpcomingWithNodeIdIncludesScopedAndGlobal(): void
    {
        $titles = array_column($this->svc->getUpcoming([$this->nodeA]), 'title');

        $this->assertContains('Org', $titles, 'global event must be visible to a scoped user');
        $this->assertContains('A', $titles);
        $this->assertNotContains('B', $titles, 'event scoped to a different node must be excluded');
    }

    public function testGetUpcomingWithMultipleNodeIdsIncludesAllMatchingScopes(): void
    {
        $titles = array_column($this->svc->getUpcoming([$this->nodeA, $this->nodeB]), 'title');
        sort($titles);
        $this->assertSame(['A', 'B', 'Org'], $titles);
    }

    public function testGetUpcomingWithEmptyArrayReturnsGlobalOnly(): void
    {
        $titles = array_column($this->svc->getUpcoming([]), 'title');
        $this->assertSame(['Org'], $titles);
    }

    public function testGetUpcomingScopedStillExcludesDrafts(): void
    {
        $this->insertEvent('Draft A', '2099-02-01 10:00:00', $this->nodeA, 0);

        $titles = array_column($this->svc->getUpcoming([$this->nodeA]), 'title');
        $this->assertNotContains('Draft A', $titles);
    }

    public function testGetUpcomingScopedRespectsLimit(): void
    {
        $this->insertEvent('A2', '2099-03-01 10:00:00', $this->nodeA, 1);
        $this->insertEvent('A3', '2099-04-01 10:00:00', $this->nodeA, 1);

        $events = $this->svc->getUpcoming([$this->nodeA], 2);

        $this->assertCount(2, $events);
        // Ordered by start_date ascending, so the first two are Org and A
        $this->assertSame(['Org', 'A'], array_column($events, 'title'));
    }

    // ── Empty-array (global only) branch for range queries ───────────────

    public function testGetForDateRangeWithEmptyArrayReturnsGlobalOnly(): void
    {
        $titles = array_column(
            $this->svc->getForDateRange('2099-01-01', '2099-01-31', []),
            'title'
        );

        $this->assertSame(['Org'], $titles);
    }

    public function testGetForMonthWithEmptyArrayReturnsGlobalOnly(): void
    {
        $titles = array_column($this->svc->getForMonth(2099, 1, []), 'title');
        $this->assertSame(['Org'], $titles);
    }
}

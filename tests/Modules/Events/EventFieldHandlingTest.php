<?php

declare(strict_types=1);

namespace Tests\Modules\Events;

use App\Core\Database;
use App\Modules\Events\Services\EventService;
use PHPUnit\Framework\TestCase;

/**
 * Field-level persistence and edge-case tests for EventService.
 *
 * Covers node_scope_id and created_by persistence and type casting, the
 * created_by_email join, end-date handling (clearing, end before start —
 * documenting that the service applies no chronology validation), and
 * getAll ordering. Complements EventServiceTest (CRUD, validation,
 * publish workflow, queries) and the scoping test files without
 * duplicating them.
 */
class EventFieldHandlingTest extends TestCase
{
    private Database $db;
    private EventService $svc;
    private int $userId;

    protected function setUp(): void
    {
        try {
            $this->db = new Database(TEST_CONFIG['db']);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
        $this->db->query("DROP TABLE IF EXISTS `events`");
        $this->db->query("DROP TABLE IF EXISTS `users`");
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");

        // Minimal users table so the LEFT JOIN in getById works
        $this->db->query("
            CREATE TABLE `users` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `email` VARCHAR(255) NOT NULL UNIQUE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->db->query("
            CREATE TABLE `events` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->userId = $this->db->insert('users', ['email' => 'leader@example.org']);

        $this->svc = new EventService($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
            $this->db->query("DROP TABLE IF EXISTS `events`");
            $this->db->query("DROP TABLE IF EXISTS `users`");
            $this->db->query("SET FOREIGN_KEY_CHECKS = 1");
        }
    }

    // ── node_scope_id ─────────────────────────────────────────────────────

    public function testCreatePersistsNodeScopeId(): void
    {
        $id = $this->svc->create([
            'title' => 'Scoped',
            'start_date' => '2099-01-01 10:00:00',
            'node_scope_id' => 7,
        ], $this->userId);

        $event = $this->svc->getById($id);
        $this->assertSame(7, $event['node_scope_id']);
    }

    public function testCreateDefaultsNodeScopeIdToNull(): void
    {
        $id = $this->svc->create([
            'title' => 'Global',
            'start_date' => '2099-01-01 10:00:00',
        ], $this->userId);

        $this->assertNull($this->svc->getById($id)['node_scope_id']);
    }

    public function testGetByIdCastsNodeScopeIdToInt(): void
    {
        $id = $this->svc->create([
            'title' => 'Cast Check',
            'start_date' => '2099-01-01 10:00:00',
            'node_scope_id' => 3,
        ], $this->userId);

        $this->assertIsInt($this->svc->getById($id)['node_scope_id']);
    }

    public function testUpdateChangesNodeScopeId(): void
    {
        $id = $this->svc->create([
            'title' => 'Rescope',
            'start_date' => '2099-01-01 10:00:00',
            'node_scope_id' => 3,
        ], $this->userId);

        $this->svc->update($id, ['node_scope_id' => 9]);
        $this->assertSame(9, $this->svc->getById($id)['node_scope_id']);
    }

    public function testUpdateClearsNodeScopeIdToGlobal(): void
    {
        $id = $this->svc->create([
            'title' => 'Widen',
            'start_date' => '2099-01-01 10:00:00',
            'node_scope_id' => 3,
        ], $this->userId);

        $this->svc->update($id, ['node_scope_id' => null]);
        $this->assertNull($this->svc->getById($id)['node_scope_id']);
    }

    // ── created_by ────────────────────────────────────────────────────────

    public function testCreatePersistsCreatedByAsInt(): void
    {
        $id = $this->svc->create([
            'title' => 'Creator',
            'start_date' => '2099-01-01 10:00:00',
        ], $this->userId);

        $event = $this->svc->getById($id);
        $this->assertSame($this->userId, $event['created_by']);
        $this->assertIsInt($event['created_by']);
    }

    public function testGetByIdJoinsCreatorEmail(): void
    {
        $id = $this->svc->create([
            'title' => 'Joined',
            'start_date' => '2099-01-01 10:00:00',
        ], $this->userId);

        $this->assertSame('leader@example.org', $this->svc->getById($id)['created_by_email']);
    }

    public function testGetByIdCreatorEmailIsNullForUnknownUser(): void
    {
        // Simulates a deleted user (FK ON DELETE SET NULL in production)
        $eventId = $this->db->insert('events', [
            'title' => 'Orphaned',
            'start_date' => '2099-01-01 10:00:00',
            'created_by' => null,
        ]);

        $event = $this->svc->getById($eventId);
        $this->assertNull($event['created_by']);
        $this->assertNull($event['created_by_email']);
    }

    // ── end_date handling ─────────────────────────────────────────────────

    public function testUpdateClearsEndDate(): void
    {
        $id = $this->svc->create([
            'title' => 'Open Ended',
            'start_date' => '2099-01-01 10:00:00',
            'end_date' => '2099-01-01 17:00:00',
        ], $this->userId);

        $this->svc->update($id, ['end_date' => null]);
        $this->assertNull($this->svc->getById($id)['end_date']);
    }

    public function testCreateAcceptsEndDateBeforeStartDate(): void
    {
        // The service performs no chronology validation; this test documents
        // that behaviour so a future validation change is a conscious one.
        $id = $this->svc->create([
            'title' => 'Reversed',
            'start_date' => '2099-06-15 10:00:00',
            'end_date' => '2099-06-14 10:00:00',
        ], $this->userId);

        $event = $this->svc->getById($id);
        $this->assertSame('2099-06-15 10:00:00', $event['start_date']);
        $this->assertSame('2099-06-14 10:00:00', $event['end_date']);
    }

    public function testCreateMultiDayAllDayEventPersistsBothDates(): void
    {
        $id = $this->svc->create([
            'title' => 'Summer Camp',
            'start_date' => '2099-08-01',
            'end_date' => '2099-08-07',
            'all_day' => 1,
        ], $this->userId);

        $event = $this->svc->getById($id);
        $this->assertTrue($event['all_day']);
        $this->assertSame('2099-08-01 00:00:00', $event['start_date']);
        $this->assertSame('2099-08-07 00:00:00', $event['end_date']);
    }

    // ── getAll ordering ───────────────────────────────────────────────────

    public function testGetAllOrdersByStartDateDescending(): void
    {
        $this->svc->create(['title' => 'Middle', 'start_date' => '2099-06-01 10:00:00'], $this->userId);
        $this->svc->create(['title' => 'Latest', 'start_date' => '2099-12-01 10:00:00'], $this->userId);
        $this->svc->create(['title' => 'Earliest', 'start_date' => '2099-01-01 10:00:00'], $this->userId);

        $titles = array_column($this->svc->getAll()['items'], 'title');
        $this->assertSame(['Latest', 'Middle', 'Earliest'], $titles);
    }
}

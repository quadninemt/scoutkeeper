<?php

declare(strict_types=1);

namespace Tests\Modules\Events;

use App\Core\Database;
use App\Modules\Events\Services\EventService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EventService.
 *
 * Covers create, update, delete, getById, publish/unpublish,
 * getUpcoming, getForDateRange, getForMonth, getAll (pagination,
 * year/month filters), getDistinctYears, and type casting.
 *
 * Does NOT duplicate node-scope filtering coverage already in
 * EventScopingTest.
 */
class EventServiceTest extends TestCase
{
    private Database $db;
    private EventService $svc;

    /** Dummy creator user ID — no users table needed */
    private int $userId = 1;

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

        // Minimal users table so the LEFT JOIN in getById does not fail
        $this->db->query("
            CREATE TABLE `users` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `email` VARCHAR(255) NOT NULL UNIQUE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->db->query("
            CREATE TABLE `events` (
                `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `title`        VARCHAR(200) NOT NULL,
                `description`  TEXT NULL,
                `location`     VARCHAR(200) NULL,
                `start_date`   DATETIME NOT NULL,
                `end_date`     DATETIME NULL,
                `all_day`      TINYINT(1) NOT NULL DEFAULT 0,
                `is_published` TINYINT(1) NOT NULL DEFAULT 0,
                `node_scope_id` INT UNSIGNED NULL,
                `created_by`   INT UNSIGNED NULL,
                `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

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

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Insert a published event and return its ID.
     */
    private function publishedEvent(string $title, string $startDate, array $extra = []): int
    {
        $id = $this->svc->create(array_merge([
            'title'      => $title,
            'start_date' => $startDate,
        ], $extra), $this->userId);

        $this->svc->publish($id);

        return $id;
    }

    // ── create ────────────────────────────────────────────────────────────

    public function testCreateReturnsPositiveId(): void
    {
        $id = $this->svc->create([
            'title'      => 'Summer Camp',
            'start_date' => '2099-07-01 09:00:00',
        ], $this->userId);

        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    public function testCreatePersistsAllOptionalFields(): void
    {
        $id = $this->svc->create([
            'title'       => '  Hike  ',
            'description' => 'Annual hike',
            'location'    => ' Forest Park ',
            'start_date'  => '2099-08-10 08:00:00',
            'end_date'    => '2099-08-10 17:00:00',
            'all_day'     => 0,
        ], $this->userId);

        $event = $this->svc->getById($id);
        $this->assertNotNull($event);
        $this->assertSame('Hike', $event['title'], 'title should be trimmed');
        $this->assertSame('Annual hike', $event['description']);
        $this->assertSame('Forest Park', $event['location'], 'location should be trimmed');
        $this->assertSame('2099-08-10 08:00:00', $event['start_date']);
        $this->assertSame('2099-08-10 17:00:00', $event['end_date']);
        $this->assertFalse($event['all_day']);
    }

    public function testCreateDefaultsToDraft(): void
    {
        $id = $this->svc->create([
            'title'      => 'Draft Event',
            'start_date' => '2099-01-01 10:00:00',
        ], $this->userId);

        $event = $this->svc->getById($id);
        $this->assertFalse($event['is_published']);
    }

    public function testCreateAllDayFlag(): void
    {
        $id = $this->svc->create([
            'title'      => 'All Day',
            'start_date' => '2099-06-15',
            'all_day'    => 1,
        ], $this->userId);

        $event = $this->svc->getById($id);
        $this->assertTrue($event['all_day']);
    }

    public function testCreateThrowsWhenTitleMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->create(['start_date' => '2099-01-01'], $this->userId);
    }

    public function testCreateThrowsWhenTitleBlank(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->create(['title' => '   ', 'start_date' => '2099-01-01'], $this->userId);
    }

    public function testCreateThrowsWhenStartDateMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->create(['title' => 'Event'], $this->userId);
    }

    public function testCreateThrowsWhenStartDateBlank(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->create(['title' => 'Event', 'start_date' => '  '], $this->userId);
    }

    // ── getById ───────────────────────────────────────────────────────────

    public function testGetByIdReturnsNullForMissingId(): void
    {
        $this->assertNull($this->svc->getById(999999));
    }

    public function testGetByIdCastsIdToInt(): void
    {
        $id = $this->svc->create([
            'title'      => 'Type Check',
            'start_date' => '2099-01-01 10:00:00',
        ], $this->userId);

        $event = $this->svc->getById($id);
        $this->assertIsInt($event['id']);
    }

    public function testGetByIdCastsBooleans(): void
    {
        $id = $this->svc->create([
            'title'      => 'Bool Check',
            'start_date' => '2099-01-01',
            'all_day'    => 1,
        ], $this->userId);
        $this->svc->publish($id);

        $event = $this->svc->getById($id);
        $this->assertIsBool($event['is_published']);
        $this->assertIsBool($event['all_day']);
        $this->assertTrue($event['is_published']);
        $this->assertTrue($event['all_day']);
    }

    // ── update ────────────────────────────────────────────────────────────

    public function testUpdateChangesTitle(): void
    {
        $id = $this->svc->create(['title' => 'Old', 'start_date' => '2099-01-01'], $this->userId);
        $this->svc->update($id, ['title' => 'New']);
        $this->assertSame('New', $this->svc->getById($id)['title']);
    }

    public function testUpdateTrimsTitle(): void
    {
        $id = $this->svc->create(['title' => 'Before', 'start_date' => '2099-01-01'], $this->userId);
        $this->svc->update($id, ['title' => '  After  ']);
        $this->assertSame('After', $this->svc->getById($id)['title']);
    }

    public function testUpdateThrowsOnEmptyTitle(): void
    {
        $id = $this->svc->create(['title' => 'Keep', 'start_date' => '2099-01-01'], $this->userId);
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->update($id, ['title' => '']);
    }

    public function testUpdateThrowsOnBlankStartDate(): void
    {
        $id = $this->svc->create(['title' => 'Keep', 'start_date' => '2099-01-01'], $this->userId);
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->update($id, ['start_date' => '  ']);
    }

    public function testUpdateChangesStartDate(): void
    {
        $id = $this->svc->create(['title' => 'Event', 'start_date' => '2099-01-01 10:00:00'], $this->userId);
        $this->svc->update($id, ['start_date' => '2099-06-15 09:00:00']);
        $this->assertSame('2099-06-15 09:00:00', $this->svc->getById($id)['start_date']);
    }

    public function testUpdateChangesAllDayFlag(): void
    {
        $id = $this->svc->create(['title' => 'Event', 'start_date' => '2099-01-01', 'all_day' => 0], $this->userId);
        $this->svc->update($id, ['all_day' => 1]);
        $this->assertTrue($this->svc->getById($id)['all_day']);
    }

    public function testUpdateSetsLocationToNull(): void
    {
        $id = $this->svc->create([
            'title'      => 'Event',
            'start_date' => '2099-01-01',
            'location'   => 'Hall',
        ], $this->userId);

        $this->svc->update($id, ['location' => null]);
        $this->assertNull($this->svc->getById($id)['location']);
    }

    public function testUpdateWithEmptyArrayDoesNothing(): void
    {
        $id = $this->svc->create(['title' => 'Unchanged', 'start_date' => '2099-01-01'], $this->userId);
        $this->svc->update($id, []);
        $this->assertSame('Unchanged', $this->svc->getById($id)['title']);
    }

    // ── delete ────────────────────────────────────────────────────────────

    public function testDeleteRemovesEvent(): void
    {
        $id = $this->svc->create(['title' => 'Gone', 'start_date' => '2099-01-01'], $this->userId);
        $this->svc->delete($id);
        $this->assertNull($this->svc->getById($id));
    }

    public function testDeleteNonExistentIdDoesNotThrow(): void
    {
        $this->svc->delete(999999);
        $this->assertTrue(true); // reached without exception
    }

    // ── publish / unpublish ───────────────────────────────────────────────

    public function testPublishSetsIsPublishedTrue(): void
    {
        $id = $this->svc->create(['title' => 'Pub', 'start_date' => '2099-01-01'], $this->userId);
        $this->assertFalse($this->svc->getById($id)['is_published']);

        $this->svc->publish($id);
        $this->assertTrue($this->svc->getById($id)['is_published']);
    }

    public function testUnpublishSetsIsPublishedFalse(): void
    {
        $id = $this->svc->create(['title' => 'Pub', 'start_date' => '2099-01-01'], $this->userId);
        $this->svc->publish($id);
        $this->assertTrue($this->svc->getById($id)['is_published']);

        $this->svc->unpublish($id);
        $this->assertFalse($this->svc->getById($id)['is_published']);
    }

    public function testPublishUnpublishRoundTrip(): void
    {
        $id = $this->svc->create(['title' => 'Toggle', 'start_date' => '2099-01-01'], $this->userId);
        $this->svc->publish($id);
        $this->svc->unpublish($id);
        $this->svc->publish($id);
        $this->assertTrue($this->svc->getById($id)['is_published']);
    }

    // ── getUpcoming ───────────────────────────────────────────────────────

    public function testGetUpcomingReturnsOnlyPublished(): void
    {
        $this->publishedEvent('Published', '2099-01-01 10:00:00');
        $this->svc->create(['title' => 'Draft', 'start_date' => '2099-01-02 10:00:00'], $this->userId);

        $events = $this->svc->getUpcoming();
        $titles = array_column($events, 'title');
        $this->assertContains('Published', $titles);
        $this->assertNotContains('Draft', $titles);
    }

    public function testGetUpcomingExcludesPastEvents(): void
    {
        // Past event: insert directly so we can backdate start_date
        $this->db->query(
            "INSERT INTO `events` (`title`, `start_date`, `is_published`) VALUES (?, ?, 1)",
            ['Past Event', '2000-01-01 10:00:00']
        );
        $this->publishedEvent('Future', '2099-12-31 10:00:00');

        $events = $this->svc->getUpcoming();
        $titles = array_column($events, 'title');
        $this->assertNotContains('Past Event', $titles);
        $this->assertContains('Future', $titles);
    }

    public function testGetUpcomingReturnsOrderedByStartDateAsc(): void
    {
        $this->publishedEvent('Third', '2099-03-01 10:00:00');
        $this->publishedEvent('First', '2099-01-01 10:00:00');
        $this->publishedEvent('Second', '2099-02-01 10:00:00');

        $titles = array_column($this->svc->getUpcoming(), 'title');
        $this->assertSame(['First', 'Second', 'Third'], $titles);
    }

    public function testGetUpcomingRespectsLimit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->publishedEvent("Event $i", "2099-0{$i}-01 10:00:00");
        }

        $events = $this->svc->getUpcoming(null, 3);
        $this->assertCount(3, $events);
    }

    public function testGetUpcomingReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->svc->getUpcoming());
    }

    // ── getForDateRange ───────────────────────────────────────────────────

    public function testGetForDateRangeReturnsEventsInRange(): void
    {
        $this->publishedEvent('In Range', '2099-06-15 10:00:00');
        $this->publishedEvent('Before', '2099-05-31 23:59:00');
        $this->publishedEvent('After', '2099-07-01 00:01:00');

        $events = $this->svc->getForDateRange('2099-06-01 00:00:00', '2099-06-30 23:59:59');
        $titles = array_column($events, 'title');
        $this->assertContains('In Range', $titles);
        $this->assertNotContains('Before', $titles);
        $this->assertNotContains('After', $titles);
    }

    public function testGetForDateRangeExcludesDraftEvents(): void
    {
        $this->svc->create(['title' => 'Hidden', 'start_date' => '2099-06-15 10:00:00'], $this->userId);

        $events = $this->svc->getForDateRange('2099-06-01', '2099-06-30');
        $this->assertEmpty($events);
    }

    public function testGetForDateRangeReturnsEmptyWhenNoMatch(): void
    {
        $this->publishedEvent('Far Future', '2150-01-01 10:00:00');
        $events = $this->svc->getForDateRange('2099-01-01', '2099-12-31');
        $this->assertEmpty($events);
    }

    // ── getForMonth ───────────────────────────────────────────────────────

    public function testGetForMonthReturnsEventsInThatMonth(): void
    {
        $this->publishedEvent('July Event', '2099-07-15 10:00:00');
        $this->publishedEvent('June Event', '2099-06-30 10:00:00');

        $events = $this->svc->getForMonth(2099, 7);
        $titles = array_column($events, 'title');
        $this->assertContains('July Event', $titles);
        $this->assertNotContains('June Event', $titles);
    }

    public function testGetForMonthBoundaryDaysIncluded(): void
    {
        $this->publishedEvent('First Day', '2099-03-01 00:00:00');
        $this->publishedEvent('Last Day', '2099-03-31 23:59:00');

        $events = $this->svc->getForMonth(2099, 3);
        $titles = array_column($events, 'title');
        $this->assertContains('First Day', $titles);
        $this->assertContains('Last Day', $titles);
    }

    public function testGetForMonthReturnsEmptyForEmptyMonth(): void
    {
        $this->assertSame([], $this->svc->getForMonth(2099, 4));
    }

    // ── getAll ────────────────────────────────────────────────────────────

    public function testGetAllReturnsPaginationStructure(): void
    {
        $result = $this->svc->getAll();
        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('page', $result);
        $this->assertArrayHasKey('pages', $result);
        $this->assertArrayHasKey('per_page', $result);
    }

    public function testGetAllReturnsAllRegardlessOfPublishedStatus(): void
    {
        $this->svc->create(['title' => 'Draft',     'start_date' => '2099-01-01'], $this->userId);
        $this->publishedEvent('Published', '2099-01-02');

        $result = $this->svc->getAll();
        $this->assertSame(2, $result['total']);
    }

    public function testGetAllPaginationPageTwoReturnsSecondSlice(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->svc->create(['title' => "E$i", 'start_date' => "2099-0{$i}-01"], $this->userId);
        }

        $page1 = $this->svc->getAll(1, 3);
        $page2 = $this->svc->getAll(2, 3);

        $this->assertCount(3, $page1['items']);
        $this->assertCount(2, $page2['items']);
        $this->assertSame(5, $page1['total']);
        $this->assertSame(2, $page1['pages']);
    }

    public function testGetAllFiltersByYear(): void
    {
        $this->svc->create(['title' => 'Year 2099', 'start_date' => '2099-06-01'], $this->userId);
        $this->svc->create(['title' => 'Year 2100', 'start_date' => '2100-06-01'], $this->userId);

        $result = $this->svc->getAll(1, 20, 2099);
        $titles = array_column($result['items'], 'title');
        $this->assertContains('Year 2099', $titles);
        $this->assertNotContains('Year 2100', $titles);
    }

    public function testGetAllFiltersByYearAndMonth(): void
    {
        $this->svc->create(['title' => 'March', 'start_date' => '2099-03-15'], $this->userId);
        $this->svc->create(['title' => 'April', 'start_date' => '2099-04-15'], $this->userId);

        $result = $this->svc->getAll(1, 20, 2099, 3);
        $titles = array_column($result['items'], 'title');
        $this->assertContains('March', $titles);
        $this->assertNotContains('April', $titles);
    }

    public function testGetAllReturnsEmptyItemsAndTotalZeroWhenNoEvents(): void
    {
        $result = $this->svc->getAll();
        $this->assertSame([], $result['items']);
        $this->assertSame(0, $result['total']);
        $this->assertSame(1, $result['pages']);
    }

    public function testGetAllPageClampsToLastPage(): void
    {
        $this->svc->create(['title' => 'Only', 'start_date' => '2099-01-01'], $this->userId);

        // Request page 99 when only 1 page exists
        $result = $this->svc->getAll(99, 20);
        $this->assertSame(1, $result['page']);
    }

    // ── getDistinctYears ──────────────────────────────────────────────────

    public function testGetDistinctYearsReturnsYearsDescending(): void
    {
        $this->svc->create(['title' => 'A', 'start_date' => '2099-01-01'], $this->userId);
        $this->svc->create(['title' => 'B', 'start_date' => '2100-01-01'], $this->userId);
        $this->svc->create(['title' => 'C', 'start_date' => '2098-01-01'], $this->userId);

        $years = $this->svc->getDistinctYears();
        $this->assertSame([2100, 2099, 2098], $years);
    }

    public function testGetDistinctYearsDeduplicatesSameYear(): void
    {
        $this->svc->create(['title' => 'Jan', 'start_date' => '2099-01-01'], $this->userId);
        $this->svc->create(['title' => 'Dec', 'start_date' => '2099-12-01'], $this->userId);

        $years = $this->svc->getDistinctYears();
        $this->assertSame([2099], $years);
    }

    public function testGetDistinctYearsReturnsEmptyWhenNoEvents(): void
    {
        $this->assertSame([], $this->svc->getDistinctYears());
    }
}

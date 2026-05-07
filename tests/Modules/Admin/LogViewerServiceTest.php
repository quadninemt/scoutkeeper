<?php

declare(strict_types=1);

namespace Tests\Modules\Admin;

use App\Modules\Admin\Services\LogViewerService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LogViewerService.
 *
 * Covers getEntries (pagination, filters: level, status, min_ms, search),
 * getSlowQueryStats, clearLog, getLogCounts, and graceful handling of
 * missing / malformed log files.
 *
 * No database connection is needed — this service is purely filesystem-based.
 */
class LogViewerServiceTest extends TestCase
{
    private string $rootPath;
    private LogViewerService $service;

    protected function setUp(): void
    {
        $this->rootPath = sys_get_temp_dir() . '/sk10_logviewer_test_' . uniqid();
        mkdir($this->rootPath . '/var/logs', 0755, true);

        $this->service = new LogViewerService($this->rootPath);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->rootPath);
    }

    // ── types() ──────────────────────────────────────────────────────────

    public function testTypesReturnsAllExpectedKeys(): void
    {
        $types = LogViewerService::types();
        $this->assertContains('errors', $types);
        $this->assertContains('requests', $types);
        $this->assertContains('slow-queries', $types);
        $this->assertContains('cron', $types);
        $this->assertContains('app', $types);
        $this->assertContains('smtp', $types);
    }

    // ── getEntries() ─ missing / empty files ─────────────────────────────

    public function testGetEntriesReturnsEmptyForMissingFile(): void
    {
        $result = $this->service->getEntries('errors');

        $this->assertSame([], $result['items']);
        $this->assertSame(0, $result['total']);
        $this->assertSame(0, $result['total_unfiltered']);
    }

    public function testGetEntriesReturnsEmptyForEmptyJsonArray(): void
    {
        $this->writeLog('errors', []);

        $result = $this->service->getEntries('errors');
        $this->assertSame([], $result['items']);
        $this->assertSame(0, $result['total']);
    }

    public function testGetEntriesHandlesMalformedJson(): void
    {
        file_put_contents($this->rootPath . '/var/logs/errors.json', 'NOT_JSON{{{');

        $result = $this->service->getEntries('errors');
        $this->assertSame([], $result['items']);
    }

    public function testGetEntriesReturnsEmptyForUnknownType(): void
    {
        $result = $this->service->getEntries('nonexistent_type');
        $this->assertSame([], $result['items']);
        $this->assertSame(0, $result['total']);
    }

    // ── getEntries() ─ pagination ─────────────────────────────────────────

    public function testGetEntriesPaginatesCorrectly(): void
    {
        $entries = [];
        for ($i = 1; $i <= 30; $i++) {
            $entries[] = ['message' => "Entry $i", 'level' => 'error'];
        }
        $this->writeLog('errors', $entries);

        $result = $this->service->getEntries('errors', 1, 10);

        $this->assertSame(30, $result['total']);
        $this->assertSame(3, $result['pages']);
        $this->assertSame(1, $result['page']);
        $this->assertCount(10, $result['items']);
    }

    public function testGetEntriesReturnsSecondPage(): void
    {
        $entries = [];
        for ($i = 1; $i <= 25; $i++) {
            $entries[] = ['id' => $i, 'message' => "Entry $i"];
        }
        $this->writeLog('app', $entries);

        $result = $this->service->getEntries('app', 2, 10);

        $this->assertSame(2, $result['page']);
        $this->assertCount(10, $result['items']);
    }

    public function testGetEntriesReturnsNewestFirst(): void
    {
        $entries = [
            ['message' => 'first',  'ts' => '2024-01-01'],
            ['message' => 'second', 'ts' => '2024-06-01'],
            ['message' => 'third',  'ts' => '2024-12-01'],
        ];
        $this->writeLog('app', $entries);

        $result = $this->service->getEntries('app');

        // The last element in the JSON array should appear first after reversing
        $this->assertSame('third', $result['items'][0]['message']);
        $this->assertSame('first', $result['items'][2]['message']);
    }

    public function testGetEntriesReturnsMetadataStructure(): void
    {
        $this->writeLog('errors', [['message' => 'oops']]);

        $result = $this->service->getEntries('errors');

        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('total_unfiltered', $result);
        $this->assertArrayHasKey('page', $result);
        $this->assertArrayHasKey('pages', $result);
    }

    public function testGetEntriesPageClampedToMax(): void
    {
        $this->writeLog('errors', [['message' => 'e']]);

        $result = $this->service->getEntries('errors', 999, 50);

        $this->assertSame(1, $result['page']);
    }

    // ── getEntries() ─ filters ────────────────────────────────────────────

    public function testGetEntriesFiltersByLevel(): void
    {
        $this->writeLog('errors', [
            ['level' => 'error', 'message' => 'bad thing'],
            ['level' => 'warning', 'message' => 'cautionary'],
            ['level' => 'error', 'message' => 'another error'],
        ]);

        $result = $this->service->getEntries('errors', 1, 50, ['level' => 'error']);

        $this->assertSame(2, $result['total']);
        $this->assertSame(3, $result['total_unfiltered']);
        foreach ($result['items'] as $item) {
            $this->assertSame('error', $item['level']);
        }
    }

    public function testGetEntriesFiltersByStatus(): void
    {
        $this->writeLog('cron', [
            ['status' => 'success', 'job' => 'email'],
            ['status' => 'failed', 'job' => 'backup'],
            ['status' => 'success', 'job' => 'cleanup'],
        ]);

        $result = $this->service->getEntries('cron', 1, 50, ['status' => 'success']);

        $this->assertSame(2, $result['total']);
    }

    public function testGetEntriesFiltersByMinMs(): void
    {
        $this->writeLog('slow-queries', [
            ['sql' => 'SELECT 1', 'elapsed_ms' => 50.0],
            ['sql' => 'SELECT 2', 'elapsed_ms' => 250.0],
            ['sql' => 'SELECT 3', 'elapsed_ms' => 500.0],
        ]);

        $result = $this->service->getEntries('slow-queries', 1, 50, ['min_ms' => 200.0]);

        $this->assertSame(2, $result['total']);
    }

    public function testGetEntriesFiltersBySearchTerm(): void
    {
        $this->writeLog('errors', [
            ['message' => 'Database connection failed', 'level' => 'error'],
            ['message' => 'File not found', 'level' => 'error'],
            ['message' => 'Memory limit exceeded', 'level' => 'error'],
        ]);

        $result = $this->service->getEntries('errors', 1, 50, ['search' => 'database']);

        $this->assertSame(1, $result['total']);
        $this->assertStringContainsString('Database', $result['items'][0]['message']);
    }

    public function testGetEntriesSearchIsCaseInsensitive(): void
    {
        $this->writeLog('app', [
            ['message' => 'UserLogin success'],
            ['message' => 'USERLOGIN failed'],
        ]);

        $result = $this->service->getEntries('app', 1, 50, ['search' => 'userlogin']);

        $this->assertSame(2, $result['total']);
    }

    public function testGetEntriesNoFiltersReturnsAll(): void
    {
        $this->writeLog('errors', [
            ['level' => 'error'],
            ['level' => 'warning'],
        ]);

        $result = $this->service->getEntries('errors', 1, 50, []);

        $this->assertSame(2, $result['total']);
        $this->assertSame(2, $result['total_unfiltered']);
    }

    // ── getSlowQueryStats() ──────────────────────────────────────────────

    public function testGetSlowQueryStatsReturnsZerosForEmptyLog(): void
    {
        $stats = $this->service->getSlowQueryStats();

        $this->assertSame(0, $stats['count']);
        $this->assertSame(0.0, $stats['avg_ms']);
        $this->assertSame(0.0, $stats['p50_ms']);
        $this->assertSame(0.0, $stats['p95_ms']);
        $this->assertSame(0.0, $stats['max_ms']);
        $this->assertSame([], $stats['top']);
    }

    public function testGetSlowQueryStatsComputesCorrectly(): void
    {
        $this->writeLog('slow-queries', [
            ['sql' => 'SELECT * FROM members', 'elapsed_ms' => 100.0],
            ['sql' => 'SELECT * FROM members', 'elapsed_ms' => 200.0],
            ['sql' => 'SELECT * FROM events', 'elapsed_ms' => 300.0],
        ]);

        $stats = $this->service->getSlowQueryStats();

        $this->assertSame(3, $stats['count']);
        $this->assertEqualsWithDelta(200.0, $stats['avg_ms'], 0.01);
        $this->assertSame(300.0, $stats['max_ms']);
    }

    public function testGetSlowQueryStatsGroupsByNormalisedSql(): void
    {
        $this->writeLog('slow-queries', [
            ['sql' => 'SELECT * FROM members', 'elapsed_ms' => 100.0],
            ['sql' => 'SELECT * FROM members', 'elapsed_ms' => 200.0],
            ['sql' => 'SELECT * FROM events', 'elapsed_ms' => 50.0],
        ]);

        $stats = $this->service->getSlowQueryStats();

        // Top queries should be present; members appears twice so count = 2
        $membersGroup = array_values(array_filter(
            $stats['top'],
            fn($g) => str_contains($g['sql'], 'members')
        ));

        $this->assertCount(1, $membersGroup);
        $this->assertSame(2, $membersGroup[0]['count']);
    }

    public function testGetSlowQueryStatsLimitsTopToTen(): void
    {
        $entries = [];
        for ($i = 0; $i < 15; $i++) {
            $entries[] = ['sql' => "SELECT * FROM table_$i", 'elapsed_ms' => (float) (($i + 1) * 10)];
        }
        $this->writeLog('slow-queries', $entries);

        $stats = $this->service->getSlowQueryStats();

        $this->assertLessThanOrEqual(10, count($stats['top']));
    }

    public function testGetSlowQueryStatsWithMinMsFilter(): void
    {
        $this->writeLog('slow-queries', [
            ['sql' => 'SELECT 1', 'elapsed_ms' => 50.0],
            ['sql' => 'SELECT 2', 'elapsed_ms' => 500.0],
        ]);

        $stats = $this->service->getSlowQueryStats(['min_ms' => 100.0]);

        $this->assertSame(1, $stats['count']);
        $this->assertSame(500.0, $stats['max_ms']);
    }

    // ── clearLog() ───────────────────────────────────────────────────────

    public function testClearLogWritesEmptyJsonArray(): void
    {
        $this->writeLog('errors', [['level' => 'error', 'message' => 'test']]);

        $this->service->clearLog('errors');

        $result = $this->service->getEntries('errors');
        $this->assertSame(0, $result['total']);
    }

    public function testClearLogCreatesFileIfNotExists(): void
    {
        $this->service->clearLog('errors');

        $path = $this->rootPath . '/var/logs/errors.json';
        $this->assertFileExists($path);
        $this->assertSame('[]', file_get_contents($path));
    }

    public function testClearLogThrowsForInvalidType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->clearLog('nonexistent_type');
    }

    public function testClearLogDoesNotAffectOtherLogs(): void
    {
        $this->writeLog('errors', [['message' => 'error entry']]);
        $this->writeLog('app', [['message' => 'app entry']]);

        $this->service->clearLog('errors');

        $appResult = $this->service->getEntries('app');
        $this->assertSame(1, $appResult['total']);
    }

    // ── getLogCounts() ───────────────────────────────────────────────────

    public function testGetLogCountsReturnsAllTypes(): void
    {
        $counts = $this->service->getLogCounts();

        foreach (LogViewerService::types() as $type) {
            $this->assertArrayHasKey($type, $counts);
        }
    }

    public function testGetLogCountsReturnsZeroForMissingFiles(): void
    {
        $counts = $this->service->getLogCounts();

        foreach ($counts as $count) {
            $this->assertSame(0, $count);
        }
    }

    public function testGetLogCountsReturnsCorrectCountForPopulatedLog(): void
    {
        $this->writeLog('errors', [
            ['message' => 'e1'],
            ['message' => 'e2'],
            ['message' => 'e3'],
        ]);

        $counts = $this->service->getLogCounts();

        $this->assertSame(3, $counts['errors']);
        $this->assertSame(0, $counts['app']);
    }

    // ── Private helpers ──────────────────────────────────────────────────

    private function writeLog(string $type, array $entries): void
    {
        $path = $this->rootPath . '/var/logs/' . $type . '.json';
        file_put_contents($path, json_encode($entries), LOCK_EX);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}

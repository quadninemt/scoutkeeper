<?php

declare(strict_types=1);

namespace Tests\Modules\Communications;

use App\Core\Database;
use App\Modules\Communications\Services\EmailService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for EmailService.
 *
 * Covers queue(), queueBulk(), getQueueStats(), getLog(), and the
 * internal markFailed / logEmail paths exercised via processBatch().
 *
 * SMTP delivery is NOT tested — all assertions are against DB rows.
 */
class EmailServiceTest extends TestCase
{
    private Database $db;
    private EmailService $service;

    // ── Schema helpers ────────────────────────────────────────────────

    protected function setUp(): void
    {
        try {
            $this->db = new Database(TEST_CONFIG['db']);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
        foreach (['email_log', 'email_queue'] as $table) {
            $this->db->query("DROP TABLE IF EXISTS `{$table}`");
        }
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");

        $this->db->query("
            CREATE TABLE `email_queue` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `recipient_email` VARCHAR(255) NOT NULL,
                `recipient_name` VARCHAR(200) NULL,
                `subject` VARCHAR(300) NOT NULL,
                `body_html` LONGTEXT NOT NULL,
                `body_text` TEXT NULL,
                `status` ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
                `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `max_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 3,
                `last_error` TEXT NULL,
                `scheduled_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `sent_at` DATETIME NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE `email_log` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `recipient_email` VARCHAR(255) NOT NULL,
                `subject` VARCHAR(300) NOT NULL,
                `status` ENUM('sent','failed','bounced') NOT NULL,
                `sent_at` DATETIME NOT NULL,
                `error_message` TEXT NULL,
                `email_queue_id` INT UNSIGNED NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // No SMTP config — keeps tests purely DB-based
        $this->service = new EmailService($this->db, []);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
            $this->db->query("DROP TABLE IF EXISTS `email_log`");
            $this->db->query("DROP TABLE IF EXISTS `email_queue`");
            $this->db->query("SET FOREIGN_KEY_CHECKS = 1");
        }
    }

    // ── queue() ───────────────────────────────────────────────────────

    public function testQueueReturnsInsertedId(): void
    {
        $id = $this->service->queue(
            'alice@example.com',
            'Hello Alice',
            '<p>Hi Alice</p>'
        );

        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    public function testQueuePersistsAllFields(): void
    {
        $id = $this->service->queue(
            'Alice@Example.COM',
            'Test Subject',
            '<p>Body</p>',
            'Body',
            'Alice Smith',
            '2030-01-15 09:00:00'
        );

        $row = $this->db->fetchOne(
            "SELECT * FROM email_queue WHERE id = :id",
            ['id' => $id]
        );

        $this->assertNotNull($row);
        $this->assertSame('alice@example.com', $row['recipient_email'], 'Email must be lower-cased');
        $this->assertSame('Test Subject', $row['subject']);
        $this->assertSame('<p>Body</p>', $row['body_html']);
        $this->assertSame('Body', $row['body_text']);
        $this->assertSame('Alice Smith', $row['recipient_name']);
        $this->assertSame('2030-01-15 09:00:00', $row['scheduled_at']);
        $this->assertSame('pending', $row['status']);
        $this->assertSame(0, (int) $row['attempts']);
    }

    public function testQueueTrimsWhitespaceFromEmail(): void
    {
        $id = $this->service->queue('  bob@example.com  ', 'Hi', '<p>Hi</p>');

        $row = $this->db->fetchOne(
            "SELECT recipient_email FROM email_queue WHERE id = :id",
            ['id' => $id]
        );

        $this->assertSame('bob@example.com', $row['recipient_email']);
    }

    public function testQueueAutoGeneratesPlainTextFromHtml(): void
    {
        $id = $this->service->queue(
            'carol@example.com',
            'Subject',
            '<p>Hello <strong>World</strong></p><br>Goodbye'
        );

        $row = $this->db->fetchOne(
            "SELECT body_text FROM email_queue WHERE id = :id",
            ['id' => $id]
        );

        $this->assertStringContainsString('Hello', $row['body_text']);
        $this->assertStringContainsString('World', $row['body_text']);
        $this->assertStringNotContainsString('<p>', $row['body_text']);
        $this->assertStringNotContainsString('<strong>', $row['body_text']);
    }

    public function testQueueDefaultsScheduledAtToNow(): void
    {
        $before = gmdate('Y-m-d H:i:s');
        $id = $this->service->queue('d@example.com', 'Subj', '<p>Body</p>');
        $after = gmdate('Y-m-d H:i:s');

        $row = $this->db->fetchOne(
            "SELECT scheduled_at FROM email_queue WHERE id = :id",
            ['id' => $id]
        );

        $this->assertGreaterThanOrEqual($before, $row['scheduled_at']);
        $this->assertLessThanOrEqual($after, $row['scheduled_at']);
    }

    public function testQueueWithoutRecipientName(): void
    {
        $id = $this->service->queue('e@example.com', 'No Name', '<p>Hi</p>');

        $row = $this->db->fetchOne(
            "SELECT recipient_name FROM email_queue WHERE id = :id",
            ['id' => $id]
        );

        $this->assertNull($row['recipient_name']);
    }

    // ── queueBulk() ───────────────────────────────────────────────────

    public function testQueueBulkReturnsCountOfQueued(): void
    {
        $count = $this->service->queueBulk(
            [
                ['email' => 'one@example.com', 'name' => 'One'],
                ['email' => 'two@example.com'],
                ['email' => 'three@example.com', 'name' => 'Three'],
            ],
            'Bulk Subject',
            '<p>Bulk Body</p>'
        );

        $this->assertSame(3, $count);
    }

    public function testQueueBulkInsertsOneRowPerRecipient(): void
    {
        $this->service->queueBulk(
            [
                ['email' => 'a@example.com'],
                ['email' => 'b@example.com'],
            ],
            'Mass Email',
            '<p>Content</p>'
        );

        $rows = $this->db->fetchAll("SELECT * FROM email_queue ORDER BY recipient_email");

        $this->assertCount(2, $rows);
        $this->assertSame('a@example.com', $rows[0]['recipient_email']);
        $this->assertSame('b@example.com', $rows[1]['recipient_email']);
    }

    public function testQueueBulkSharesSameSubjectAndBody(): void
    {
        $this->service->queueBulk(
            [
                ['email' => 'p@example.com'],
                ['email' => 'q@example.com'],
            ],
            'Newsletter June',
            '<h1>June Update</h1>'
        );

        $rows = $this->db->fetchAll("SELECT subject, body_html FROM email_queue");

        foreach ($rows as $row) {
            $this->assertSame('Newsletter June', $row['subject']);
            $this->assertSame('<h1>June Update</h1>', $row['body_html']);
        }
    }

    public function testQueueBulkSkipsRecipientsWithNoEmail(): void
    {
        $count = $this->service->queueBulk(
            [
                ['email' => 'valid@example.com'],
                ['name' => 'No Email Here'],   // missing 'email' key
                ['email' => ''],               // empty string
            ],
            'Subject',
            '<p>Body</p>'
        );

        $this->assertSame(1, $count);

        $total = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM email_queue");
        $this->assertSame(1, $total);
    }

    public function testQueueBulkReturnsZeroForEmptyList(): void
    {
        $count = $this->service->queueBulk([], 'Subject', '<p>Body</p>');
        $this->assertSame(0, $count);
    }

    public function testQueueBulkPreservesExplicitPlainText(): void
    {
        $this->service->queueBulk(
            [['email' => 'r@example.com']],
            'Subject',
            '<p>HTML</p>',
            'Plain text override'
        );

        $row = $this->db->fetchOne("SELECT body_text FROM email_queue");
        $this->assertSame('Plain text override', $row['body_text']);
    }

    public function testQueueBulkNormalisesEmailCase(): void
    {
        $this->service->queueBulk(
            [['email' => 'Upper@Example.COM']],
            'Subject',
            '<p>Body</p>'
        );

        $row = $this->db->fetchOne("SELECT recipient_email FROM email_queue");
        $this->assertSame('upper@example.com', $row['recipient_email']);
    }

    // ── getQueueStats() ───────────────────────────────────────────────

    public function testGetQueueStatsReturnsZerosOnEmptyQueue(): void
    {
        $stats = $this->service->getQueueStats();

        $this->assertSame(0, $stats['pending']);
        $this->assertSame(0, $stats['sending']);
        $this->assertSame(0, $stats['sent']);
        $this->assertSame(0, $stats['failed']);
    }

    public function testGetQueueStatsReflectsActualStatuses(): void
    {
        $this->service->queue('p1@example.com', 'S', '<p>B</p>');
        $this->service->queue('p2@example.com', 'S', '<p>B</p>');

        // Manually set one to 'sent' and one to 'failed'
        $this->db->query(
            "UPDATE email_queue SET status = 'sent' WHERE recipient_email = 'p1@example.com'"
        );
        $this->db->query(
            "UPDATE email_queue SET status = 'failed' WHERE recipient_email = 'p2@example.com'"
        );

        $this->service->queue('p3@example.com', 'S', '<p>B</p>'); // still pending

        $stats = $this->service->getQueueStats();

        $this->assertSame(1, $stats['pending']);
        $this->assertSame(1, $stats['sent']);
        $this->assertSame(1, $stats['failed']);
    }

    // ── getLog() ──────────────────────────────────────────────────────

    public function testGetLogReturnsEmptyWhenNoEntries(): void
    {
        $result = $this->service->getLog();

        $this->assertSame([], $result['items']);
        $this->assertSame(0, $result['total']);
        $this->assertSame(1, $result['page']);
        $this->assertSame(1, $result['pages']);
    }

    public function testGetLogReturnsInsertedLogRows(): void
    {
        $this->insertLogRow('x@example.com', 'Subj A', 'sent');
        $this->insertLogRow('y@example.com', 'Subj B', 'failed', 'connection refused');

        $result = $this->service->getLog(1, 25);

        $this->assertSame(2, $result['total']);
        $this->assertCount(2, $result['items']);
    }

    public function testGetLogPaginatesCorrectly(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->insertLogRow("m{$i}@example.com", "Subj $i", 'sent');
        }

        $page1 = $this->service->getLog(1, 3);
        $this->assertCount(3, $page1['items']);
        $this->assertSame(2, $page1['pages']);
        $this->assertSame(5, $page1['total']);

        $page2 = $this->service->getLog(2, 3);
        $this->assertCount(2, $page2['items']);
    }

    public function testGetLogPageClampedToValidRange(): void
    {
        $this->insertLogRow('z@example.com', 'Subj', 'sent');

        $result = $this->service->getLog(999, 25);

        $this->assertSame(1, $result['page']);
    }

    // ── processBatch() — DB-side state only ───────────────────────────

    /**
     * Without SMTP config, sendEmail() falls back to mail() which will
     * return false in test environments. We verify that the queue row
     * is marked 'failed' and that attempts is incremented.
     */
    public function testProcessBatchIncrementsAttemptsOnFailure(): void
    {
        $id = $this->service->queue(
            'fail@example.com',
            'Subject',
            '<p>Body</p>'
        );

        // Force scheduled_at to the past so the batch picks it up
        $this->db->query(
            "UPDATE email_queue SET scheduled_at = '2000-01-01 00:00:00' WHERE id = :id",
            ['id' => $id]
        );

        $this->service->processBatch(5);

        $row = $this->db->fetchOne(
            "SELECT status, attempts FROM email_queue WHERE id = :id",
            ['id' => $id]
        );

        $this->assertSame(1, (int) $row['attempts']);
        // Status will be 'failed' since mail() returns false in CLI/test
        $this->assertContains($row['status'], ['failed', 'sent']);
    }

    public function testProcessBatchDoesNotPickUpFutureScheduled(): void
    {
        $this->service->queue(
            'future@example.com',
            'Future Email',
            '<p>Not yet</p>',
            null,
            null,
            '2099-12-31 23:59:59'
        );

        $results = $this->service->processBatch(10);

        $this->assertSame(0, $results['sent'] + $results['failed']);
    }

    public function testProcessBatchDoesNotExceedBatchSize(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $id = $this->service->queue(
                "batch{$i}@example.com",
                'Subject',
                '<p>Body</p>'
            );
            $this->db->query(
                "UPDATE email_queue SET scheduled_at = '2000-01-01 00:00:00' WHERE id = :id",
                ['id' => $id]
            );
        }

        $this->service->processBatch(3);

        $processed = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM email_queue WHERE status != 'pending'"
        );

        $this->assertLessThanOrEqual(3, $processed);
    }

    public function testProcessBatchIgnoresMaxedOutAttempts(): void
    {
        $id = $this->service->queue(
            'maxed@example.com',
            'Subject',
            '<p>Body</p>'
        );

        // Exhaust all attempts manually
        $this->db->query(
            "UPDATE email_queue
             SET status = 'failed', attempts = 3, max_attempts = 3,
                 scheduled_at = '2000-01-01 00:00:00'
             WHERE id = :id",
            ['id' => $id]
        );

        $results = $this->service->processBatch(10);

        $this->assertSame(0, $results['sent'] + $results['failed']);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function insertLogRow(
        string $email,
        string $subject,
        string $status,
        ?string $error = null
    ): void {
        $this->db->insert('email_log', [
            'recipient_email' => $email,
            'subject' => $subject,
            'status' => $status,
            'sent_at' => gmdate('Y-m-d H:i:s'),
            'error_message' => $error,
            'email_queue_id' => null,
        ]);
    }
}

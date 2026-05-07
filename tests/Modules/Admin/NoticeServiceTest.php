<?php

declare(strict_types=1);

namespace Tests\Modules\Admin;

use App\Core\Database;
use App\Modules\Admin\Services\NoticeService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NoticeService.
 *
 * Covers create/update/deactivate CRUD, retrieval (getActive, getAll,
 * getById), acknowledgement recording, hasAcknowledged, and the
 * getUnacknowledgedForUser query.
 */
class NoticeServiceTest extends TestCase
{
    private Database $db;
    private NoticeService $service;
    private int $userId;

    protected function setUp(): void
    {
        try {
            $this->db = new Database(TEST_CONFIG['db']);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->query('DROP TABLE IF EXISTS `notice_acknowledgements`');
        $this->db->query('DROP TABLE IF EXISTS `notices`');
        $this->db->query('DROP TABLE IF EXISTS `users`');
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');

        $this->db->query("
            CREATE TABLE `users` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `email` VARCHAR(255) NOT NULL UNIQUE,
                `password_hash` VARCHAR(255) NOT NULL DEFAULT '',
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE `notices` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `title` VARCHAR(255) NOT NULL,
                `content` TEXT NOT NULL,
                `type` ENUM('must_acknowledge','informational') NOT NULL DEFAULT 'informational',
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_by` INT UNSIGNED NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT `fk_notice_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE `notice_acknowledgements` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `notice_id` INT UNSIGNED NOT NULL,
                `user_id` INT UNSIGNED NOT NULL,
                `acknowledged_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_notice_user` (`notice_id`, `user_id`),
                CONSTRAINT `fk_ack_notice` FOREIGN KEY (`notice_id`) REFERENCES `notices` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_ack_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->userId = $this->db->insert('users', [
            'email' => 'admin@example.com',
            'password_hash' => password_hash('test', PASSWORD_BCRYPT),
        ]);

        $this->service = new NoticeService($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
            $this->db->query('DROP TABLE IF EXISTS `notice_acknowledgements`');
            $this->db->query('DROP TABLE IF EXISTS `notices`');
            $this->db->query('DROP TABLE IF EXISTS `users`');
            $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    // ── create() ─────────────────────────────────────────────────────────

    public function testCreateReturnsNewId(): void
    {
        $id = $this->service->create(['title' => 'Test Notice', 'content' => 'Body'], $this->userId);
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    public function testCreateStoresAllFields(): void
    {
        $id = $this->service->create([
            'title'   => 'Safety Alert',
            'content' => 'Please review the safety procedures.',
            'type'    => 'must_acknowledge',
        ], $this->userId);

        $notice = $this->service->getById($id);

        $this->assertSame('Safety Alert', $notice['title']);
        $this->assertSame('Please review the safety procedures.', $notice['content']);
        $this->assertSame('must_acknowledge', $notice['type']);
        $this->assertSame(1, (int) $notice['is_active']);
        $this->assertSame($this->userId, (int) $notice['created_by']);
    }

    public function testCreateDefaultsTypeToInformational(): void
    {
        $id = $this->service->create(['title' => 'Info', 'content' => 'Body'], $this->userId);
        $notice = $this->service->getById($id);
        $this->assertSame('informational', $notice['type']);
    }

    public function testCreateTrimsTitleWhitespace(): void
    {
        $id = $this->service->create(['title' => '  Padded Title  ', 'content' => 'Body'], $this->userId);
        $notice = $this->service->getById($id);
        $this->assertSame('Padded Title', $notice['title']);
    }

    public function testCreateThrowsWhenTitleIsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/title/i');

        $this->service->create(['title' => '   ', 'content' => 'Body'], $this->userId);
    }

    public function testCreateThrowsWhenContentIsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/content/i');

        $this->service->create(['title' => 'Title', 'content' => ''], $this->userId);
    }

    public function testCreateThrowsForInvalidType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/type/i');

        $this->service->create(['title' => 'T', 'content' => 'C', 'type' => 'spam'], $this->userId);
    }

    // ── update() ─────────────────────────────────────────────────────────

    public function testUpdateChangesTitle(): void
    {
        $id = $this->service->create(['title' => 'Old Title', 'content' => 'Body'], $this->userId);
        $this->service->update($id, ['title' => 'New Title']);

        $notice = $this->service->getById($id);
        $this->assertSame('New Title', $notice['title']);
    }

    public function testUpdateChangesContent(): void
    {
        $id = $this->service->create(['title' => 'Title', 'content' => 'Old body'], $this->userId);
        $this->service->update($id, ['content' => 'Updated body']);

        $notice = $this->service->getById($id);
        $this->assertSame('Updated body', $notice['content']);
    }

    public function testUpdateChangesType(): void
    {
        $id = $this->service->create(['title' => 'T', 'content' => 'C', 'type' => 'informational'], $this->userId);
        $this->service->update($id, ['type' => 'must_acknowledge']);

        $notice = $this->service->getById($id);
        $this->assertSame('must_acknowledge', $notice['type']);
    }

    public function testUpdateThrowsWhenTitleSetToEmpty(): void
    {
        $id = $this->service->create(['title' => 'Title', 'content' => 'Body'], $this->userId);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->update($id, ['title' => '']);
    }

    public function testUpdateThrowsForInvalidType(): void
    {
        $id = $this->service->create(['title' => 'T', 'content' => 'C'], $this->userId);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->update($id, ['type' => 'unknown']);
    }

    public function testUpdateWithEmptyArrayDoesNothing(): void
    {
        $id = $this->service->create(['title' => 'Original', 'content' => 'Body'], $this->userId);
        $this->service->update($id, []);

        $notice = $this->service->getById($id);
        $this->assertSame('Original', $notice['title']);
    }

    // ── deactivate() ─────────────────────────────────────────────────────

    public function testDeactivateSetsIsActiveToZero(): void
    {
        $id = $this->service->create(['title' => 'T', 'content' => 'C'], $this->userId);
        $this->assertSame(1, (int) $this->service->getById($id)['is_active']);

        $this->service->deactivate($id);

        $this->assertSame(0, (int) $this->service->getById($id)['is_active']);
    }

    // ── getActive() ──────────────────────────────────────────────────────

    public function testGetActiveReturnsOnlyActiveNotices(): void
    {
        $id1 = $this->service->create(['title' => 'Active', 'content' => 'C'], $this->userId);
        $id2 = $this->service->create(['title' => 'Deactivated', 'content' => 'C'], $this->userId);
        $this->service->deactivate($id2);

        $active = $this->service->getActive();

        $titles = array_column($active, 'title');
        $this->assertContains('Active', $titles);
        $this->assertNotContains('Deactivated', $titles);
    }

    public function testGetActiveIncludesCreatorEmail(): void
    {
        $this->service->create(['title' => 'T', 'content' => 'C'], $this->userId);

        $active = $this->service->getActive();

        $this->assertSame('admin@example.com', $active[0]['created_by_email']);
    }

    public function testGetActiveReturnsNewestFirst(): void
    {
        $this->db->query(
            "INSERT INTO `notices` (`title`, `content`, `type`, `is_active`, `created_by`, `created_at`)
             VALUES ('First', 'C', 'informational', 1, ?, '2024-01-01 10:00:00')",
            [$this->userId]
        );
        $this->db->query(
            "INSERT INTO `notices` (`title`, `content`, `type`, `is_active`, `created_by`, `created_at`)
             VALUES ('Second', 'C', 'informational', 1, ?, '2024-01-01 11:00:00')",
            [$this->userId]
        );

        $active = $this->service->getActive();
        // The last inserted should appear first (newest)
        $this->assertSame('Second', $active[0]['title']);
    }

    // ── getAll() ─────────────────────────────────────────────────────────

    public function testGetAllReturnsBothActiveAndInactive(): void
    {
        $id1 = $this->service->create(['title' => 'Active', 'content' => 'C'], $this->userId);
        $id2 = $this->service->create(['title' => 'Inactive', 'content' => 'C'], $this->userId);
        $this->service->deactivate($id2);

        $all = $this->service->getAll();

        $titles = array_column($all, 'title');
        $this->assertContains('Active', $titles);
        $this->assertContains('Inactive', $titles);
    }

    // ── getById() ────────────────────────────────────────────────────────

    public function testGetByIdReturnsNotice(): void
    {
        $id = $this->service->create(['title' => 'Find Me', 'content' => 'Body'], $this->userId);

        $notice = $this->service->getById($id);

        $this->assertNotNull($notice);
        $this->assertSame('Find Me', $notice['title']);
    }

    public function testGetByIdReturnsNullForMissingNotice(): void
    {
        $this->assertNull($this->service->getById(999999));
    }

    // ── acknowledge() ────────────────────────────────────────────────────

    public function testAcknowledgeRecordsAcknowledgement(): void
    {
        $id = $this->service->create(['title' => 'T', 'content' => 'C', 'type' => 'must_acknowledge'], $this->userId);

        $this->assertFalse($this->service->hasAcknowledged($id, $this->userId));

        $this->service->acknowledge($id, $this->userId);

        $this->assertTrue($this->service->hasAcknowledged($id, $this->userId));
    }

    public function testAcknowledgeTwiceDoesNotDuplicate(): void
    {
        $id = $this->service->create(['title' => 'T', 'content' => 'C', 'type' => 'must_acknowledge'], $this->userId);

        $this->service->acknowledge($id, $this->userId);
        $this->service->acknowledge($id, $this->userId); // Second call should be idempotent

        $count = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM `notice_acknowledgements` WHERE `notice_id` = ? AND `user_id` = ?",
            [$id, $this->userId]
        );
        $this->assertSame(1, (int) $count);
    }

    // ── getUnacknowledgedForUser() ────────────────────────────────────────

    public function testGetUnacknowledgedForUserReturnsUnacknowledgedMustAcknowledge(): void
    {
        $id = $this->service->create(['title' => 'Policy', 'content' => 'C', 'type' => 'must_acknowledge'], $this->userId);

        $unacked = $this->service->getUnacknowledgedForUser($this->userId);

        $ids = array_column($unacked, 'id');
        $this->assertContains($id, array_map('intval', $ids));
    }

    public function testGetUnacknowledgedForUserExcludesAcknowledgedNotices(): void
    {
        $id = $this->service->create(['title' => 'Policy', 'content' => 'C', 'type' => 'must_acknowledge'], $this->userId);
        $this->service->acknowledge($id, $this->userId);

        $unacked = $this->service->getUnacknowledgedForUser($this->userId);

        $ids = array_column($unacked, 'id');
        $this->assertNotContains($id, array_map('intval', $ids));
    }

    public function testGetUnacknowledgedForUserExcludesInformationalNotices(): void
    {
        $this->service->create(['title' => 'Info', 'content' => 'C', 'type' => 'informational'], $this->userId);

        $unacked = $this->service->getUnacknowledgedForUser($this->userId);

        $this->assertSame([], $unacked);
    }

    public function testGetUnacknowledgedForUserExcludesInactiveNotices(): void
    {
        $id = $this->service->create(['title' => 'T', 'content' => 'C', 'type' => 'must_acknowledge'], $this->userId);
        $this->service->deactivate($id);

        $unacked = $this->service->getUnacknowledgedForUser($this->userId);

        $this->assertSame([], $unacked);
    }

    // ── getAcknowledgementReport() ────────────────────────────────────────

    public function testGetAcknowledgementReportContainsUserEmail(): void
    {
        $id = $this->service->create(['title' => 'T', 'content' => 'C', 'type' => 'must_acknowledge'], $this->userId);
        $this->service->acknowledge($id, $this->userId);

        $report = $this->service->getAcknowledgementReport($id);

        $this->assertCount(1, $report);
        $this->assertSame('admin@example.com', $report[0]['user_email']);
    }

    public function testGetAcknowledgementReportReturnsEmptyForNoAcknowledgements(): void
    {
        $id = $this->service->create(['title' => 'T', 'content' => 'C', 'type' => 'must_acknowledge'], $this->userId);

        $this->assertSame([], $this->service->getAcknowledgementReport($id));
    }
}

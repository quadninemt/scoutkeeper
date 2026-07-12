<?php

declare(strict_types=1);

namespace Tests\Modules\Admin;

use App\Core\Database;
use App\Modules\Admin\Services\TermsService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TermsService.
 *
 * Covers version CRUD (create/update with validation and HTML
 * sanitisation), the publish/unpublish workflow (per-policy exclusivity),
 * retrieval queries, acceptance recording and reporting, grace period
 * evaluation, and the requiresAcceptance decision.
 */
class TermsServiceTest extends TestCase
{
    private Database $db;
    private TermsService $service;
    private int $userId;
    private int $policyId;

    protected function setUp(): void
    {
        try {
            $this->db = new Database(TEST_CONFIG['db']);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        $this->dropTables();

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
            CREATE TABLE `policies` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(200) NOT NULL,
                `description` TEXT NULL,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_by` INT UNSIGNED NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_policy_active` (`is_active`),
                CONSTRAINT `fk_policy_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE `terms_versions` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `policy_id` INT UNSIGNED NOT NULL,
                `title` VARCHAR(300) NOT NULL,
                `content` LONGTEXT NOT NULL,
                `version_number` VARCHAR(20) NOT NULL,
                `is_published` TINYINT(1) NOT NULL DEFAULT 0,
                `published_at` DATETIME NULL,
                `grace_period_days` INT UNSIGNED NOT NULL DEFAULT 14,
                `created_by` INT UNSIGNED NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_terms_policy_pub` (`policy_id`, `is_published`),
                CONSTRAINT `fk_terms_policy` FOREIGN KEY (`policy_id`) REFERENCES `policies` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_terms_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE `terms_acceptances` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `terms_version_id` INT UNSIGNED NOT NULL,
                `user_id` INT UNSIGNED NOT NULL,
                `accepted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `ip_address` VARCHAR(45) NULL,
                UNIQUE KEY `uq_terms_user` (`terms_version_id`, `user_id`),
                CONSTRAINT `fk_acceptance_terms` FOREIGN KEY (`terms_version_id`) REFERENCES `terms_versions` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_acceptance_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->userId = $this->db->insert('users', [
            'email' => 'admin@example.com',
            'password_hash' => password_hash('test', PASSWORD_BCRYPT),
        ]);

        $this->policyId = $this->db->insert('policies', [
            'name' => 'General Policy',
            'is_active' => 1,
            'created_by' => $this->userId,
        ]);

        $this->service = new TermsService($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->dropTables();
        }
    }

    private function dropTables(): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->query('DROP TABLE IF EXISTS `terms_acceptances`');
        $this->db->query('DROP TABLE IF EXISTS `terms_versions`');
        $this->db->query('DROP TABLE IF EXISTS `policies`');
        $this->db->query('DROP TABLE IF EXISTS `users`');
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * Create a draft version through the service with sensible defaults.
     */
    private function createDraft(array $overrides = []): int
    {
        return $this->service->createVersion(array_merge([
            'policy_id' => $this->policyId,
            'title' => 'Terms of Use',
            'content' => '<p>Be excellent to each other.</p>',
            'version_number' => '1.0',
        ], $overrides), $this->userId);
    }

    // ── createVersion() ──────────────────────────────────────────────────

    public function testCreateVersionReturnsNewId(): void
    {
        $id = $this->createDraft();

        $this->assertGreaterThan(0, $id);
    }

    public function testCreateVersionStoresFieldsWithDefaults(): void
    {
        $id = $this->createDraft(['title' => '  Padded Title  ']);

        $version = $this->service->getVersionById($id);

        $this->assertSame('Padded Title', $version['title']);
        $this->assertSame('1.0', $version['version_number']);
        $this->assertSame(14, (int) $version['grace_period_days']);
        $this->assertSame(0, (int) $version['is_published']);
        $this->assertNull($version['published_at']);
        $this->assertSame($this->userId, (int) $version['created_by']);
        $this->assertSame($this->policyId, (int) $version['policy_id']);
    }

    public function testCreateVersionStoresCustomGracePeriod(): void
    {
        $id = $this->createDraft(['grace_period_days' => 30]);

        $version = $this->service->getVersionById($id);

        $this->assertSame(30, (int) $version['grace_period_days']);
    }

    public function testCreateVersionSanitizesHtmlContent(): void
    {
        $id = $this->createDraft([
            'content' => '<p>Safe paragraph</p><script>alert("xss")</script>',
        ]);

        $version = $this->service->getVersionById($id);

        $this->assertStringContainsString('<p>Safe paragraph</p>', $version['content']);
        $this->assertStringNotContainsString('<script', $version['content']);
        $this->assertStringNotContainsString('alert("xss")', $version['content']);
    }

    public function testCreateVersionThrowsWhenTitleMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/title/i');

        $this->createDraft(['title' => '   ']);
    }

    public function testCreateVersionThrowsWhenContentMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/content/i');

        $this->createDraft(['content' => '']);
    }

    public function testCreateVersionThrowsWhenVersionNumberMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/version/i');

        $this->createDraft(['version_number' => ' ']);
    }

    public function testCreateVersionThrowsWhenPolicyMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/policy/i');

        $this->createDraft(['policy_id' => 0]);
    }

    // ── updateVersion() ──────────────────────────────────────────────────

    public function testUpdateVersionChangesFields(): void
    {
        $id = $this->createDraft();

        $this->service->updateVersion($id, [
            'title' => 'Revised Terms',
            'version_number' => '1.1',
            'grace_period_days' => 7,
        ]);

        $version = $this->service->getVersionById($id);

        $this->assertSame('Revised Terms', $version['title']);
        $this->assertSame('1.1', $version['version_number']);
        $this->assertSame(7, (int) $version['grace_period_days']);
    }

    public function testUpdateVersionSanitizesContent(): void
    {
        $id = $this->createDraft();

        $this->service->updateVersion($id, [
            'content' => '<p>Updated</p><script>steal()</script>',
        ]);

        $version = $this->service->getVersionById($id);

        $this->assertStringContainsString('<p>Updated</p>', $version['content']);
        $this->assertStringNotContainsString('<script', $version['content']);
    }

    public function testUpdateVersionThrowsWhenTitleEmpty(): void
    {
        $id = $this->createDraft();

        $this->expectException(\InvalidArgumentException::class);
        $this->service->updateVersion($id, ['title' => '  ']);
    }

    public function testUpdateVersionWithEmptyArrayDoesNothing(): void
    {
        $id = $this->createDraft(['title' => 'Original']);

        $this->service->updateVersion($id, []);

        $this->assertSame('Original', $this->service->getVersionById($id)['title']);
    }

    // ── publishVersion() ─────────────────────────────────────────────────

    public function testPublishVersionMarksPublishedAndSetsTimestamp(): void
    {
        $id = $this->createDraft();

        $this->service->publishVersion($id);

        $version = $this->service->getVersionById($id);
        $this->assertSame(1, (int) $version['is_published']);
        $this->assertNotNull($version['published_at']);
    }

    public function testPublishVersionUnpublishesPreviousVersionOfSamePolicy(): void
    {
        $first = $this->createDraft(['version_number' => '1.0']);
        $second = $this->createDraft(['version_number' => '2.0']);

        $this->service->publishVersion($first);
        $this->service->publishVersion($second);

        $this->assertSame(0, (int) $this->service->getVersionById($first)['is_published']);
        $this->assertSame(1, (int) $this->service->getVersionById($second)['is_published']);
    }

    public function testPublishVersionLeavesOtherPoliciesPublished(): void
    {
        $otherPolicyId = $this->db->insert('policies', ['name' => 'Privacy Policy', 'is_active' => 1]);

        $general = $this->createDraft();
        $privacy = $this->createDraft(['policy_id' => $otherPolicyId, 'title' => 'Privacy']);

        $this->service->publishVersion($privacy);
        $this->service->publishVersion($general);

        $this->assertSame(1, (int) $this->service->getVersionById($privacy)['is_published']);
        $this->assertSame(1, (int) $this->service->getVersionById($general)['is_published']);
    }

    public function testPublishVersionWithUnknownIdIsNoOp(): void
    {
        $id = $this->createDraft();
        $this->service->publishVersion($id);

        $this->service->publishVersion(999999);

        // Existing published version must be untouched
        $this->assertSame(1, (int) $this->service->getVersionById($id)['is_published']);
    }

    // ── Retrieval ────────────────────────────────────────────────────────

    public function testGetVersionsByPolicyReturnsOnlyThatPolicy(): void
    {
        $otherPolicyId = $this->db->insert('policies', ['name' => 'Privacy Policy', 'is_active' => 1]);
        $this->createDraft(['title' => 'General v1']);
        $this->createDraft(['policy_id' => $otherPolicyId, 'title' => 'Privacy v1']);

        $versions = $this->service->getVersionsByPolicy($this->policyId);

        $titles = array_column($versions, 'title');
        $this->assertContains('General v1', $titles);
        $this->assertNotContains('Privacy v1', $titles);
    }

    public function testGetVersionsReturnsNewestFirst(): void
    {
        $this->db->query(
            "INSERT INTO `terms_versions`
                 (`policy_id`, `title`, `content`, `version_number`, `created_by`, `created_at`)
             VALUES (?, 'Older', 'C', '1.0', ?, '2024-01-01 10:00:00')",
            [$this->policyId, $this->userId]
        );
        $this->db->query(
            "INSERT INTO `terms_versions`
                 (`policy_id`, `title`, `content`, `version_number`, `created_by`, `created_at`)
             VALUES (?, 'Newer', 'C', '2.0', ?, '2024-06-01 10:00:00')",
            [$this->policyId, $this->userId]
        );

        $versions = $this->service->getVersions();

        $this->assertSame('Newer', $versions[0]['title']);
        $this->assertSame('Older', $versions[1]['title']);
    }

    public function testGetCurrentVersionReturnsPublishedVersion(): void
    {
        $draft = $this->createDraft(['version_number' => '1.0']);
        $published = $this->createDraft(['version_number' => '2.0']);
        $this->service->publishVersion($published);

        $current = $this->service->getCurrentVersion();

        $this->assertNotNull($current);
        $this->assertSame($published, (int) $current['id']);
        $this->assertNotSame($draft, (int) $current['id']);
    }

    public function testGetCurrentVersionReturnsNullWhenNothingPublished(): void
    {
        $this->createDraft();

        $this->assertNull($this->service->getCurrentVersion());
    }

    public function testGetVersionByIdIncludesCreatorEmail(): void
    {
        $id = $this->createDraft();

        $version = $this->service->getVersionById($id);

        $this->assertSame('admin@example.com', $version['created_by_email']);
    }

    public function testGetVersionByIdReturnsNullForMissingVersion(): void
    {
        $this->assertNull($this->service->getVersionById(999999));
    }

    // ── acceptTerms() / hasAccepted() ────────────────────────────────────

    public function testAcceptTermsRecordsAcceptance(): void
    {
        $id = $this->createDraft();

        $this->assertFalse($this->service->hasAccepted($this->userId, $id));

        $this->service->acceptTerms($id, $this->userId, '192.0.2.1');

        $this->assertTrue($this->service->hasAccepted($this->userId, $id));
    }

    public function testAcceptTermsIsIdempotent(): void
    {
        $id = $this->createDraft();

        $this->service->acceptTerms($id, $this->userId);
        $this->service->acceptTerms($id, $this->userId);

        $count = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM `terms_acceptances` WHERE `terms_version_id` = ? AND `user_id` = ?",
            [$id, $this->userId]
        );
        $this->assertSame(1, (int) $count);
    }

    public function testHasAcceptedIsTrueWhenNothingIsPublished(): void
    {
        // No published version means there is nothing to accept
        $this->assertTrue($this->service->hasAccepted($this->userId));
    }

    public function testHasAcceptedDefaultsToCurrentPublishedVersion(): void
    {
        $id = $this->createDraft();
        $this->service->publishVersion($id);

        $this->assertFalse($this->service->hasAccepted($this->userId));

        $this->service->acceptTerms($id, $this->userId);

        $this->assertTrue($this->service->hasAccepted($this->userId));
    }

    // ── getAcceptanceReport() ────────────────────────────────────────────

    public function testGetAcceptanceReportContainsUserEmail(): void
    {
        $id = $this->createDraft();
        $this->service->acceptTerms($id, $this->userId, '192.0.2.9');

        $report = $this->service->getAcceptanceReport($id);

        $this->assertCount(1, $report);
        $this->assertSame('admin@example.com', $report[0]['user_email']);
        $this->assertSame('192.0.2.9', $report[0]['ip_address']);
    }

    public function testGetAcceptanceReportReturnsEmptyForNoAcceptances(): void
    {
        $id = $this->createDraft();

        $this->assertSame([], $this->service->getAcceptanceReport($id));
    }

    // ── isInGracePeriod() ────────────────────────────────────────────────

    public function testIsInGracePeriodTrueJustAfterPublishing(): void
    {
        $id = $this->createDraft(['grace_period_days' => 365]);
        $this->service->publishVersion($id);

        $this->assertTrue($this->service->isInGracePeriod());
    }

    public function testIsInGracePeriodFalseAfterExpiry(): void
    {
        $id = $this->createDraft(['grace_period_days' => 14]);
        $this->service->publishVersion($id);
        $this->db->update('terms_versions', ['published_at' => '2020-01-01 00:00:00'], ['id' => $id]);

        $this->assertFalse($this->service->isInGracePeriod());
    }

    public function testIsInGracePeriodFalseWhenNothingPublished(): void
    {
        $this->createDraft();

        $this->assertFalse($this->service->isInGracePeriod());
    }

    // ── requiresAcceptance() ─────────────────────────────────────────────

    public function testRequiresAcceptanceFalseWhenNothingPublished(): void
    {
        $this->createDraft();

        $this->assertFalse($this->service->requiresAcceptance($this->userId));
    }

    public function testRequiresAcceptanceFalseDuringGracePeriod(): void
    {
        $id = $this->createDraft(['grace_period_days' => 365]);
        $this->service->publishVersion($id);

        $this->assertFalse($this->service->requiresAcceptance($this->userId));
    }

    public function testRequiresAcceptanceTrueAfterGraceExpiryWithoutAcceptance(): void
    {
        $id = $this->createDraft(['grace_period_days' => 14]);
        $this->service->publishVersion($id);
        $this->db->update('terms_versions', ['published_at' => '2020-01-01 00:00:00'], ['id' => $id]);

        $this->assertTrue($this->service->requiresAcceptance($this->userId));
    }

    public function testRequiresAcceptanceFalseOnceAccepted(): void
    {
        $id = $this->createDraft(['grace_period_days' => 14]);
        $this->service->publishVersion($id);
        $this->db->update('terms_versions', ['published_at' => '2020-01-01 00:00:00'], ['id' => $id]);
        $this->service->acceptTerms($id, $this->userId);

        $this->assertFalse($this->service->requiresAcceptance($this->userId));
    }
}

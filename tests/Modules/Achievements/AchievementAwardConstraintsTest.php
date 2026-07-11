<?php

declare(strict_types=1);

namespace Tests\Modules\Achievements;

use App\Core\Database;
use App\Modules\Achievements\Services\AchievementService;
use PHPUnit\Framework\TestCase;

/**
 * Constraint and history tests for AchievementService.
 *
 * Complements AchievementServiceTest by modelling the *production* schema
 * from migration 0013_achievements.sql, which the basic test omits:
 *
 *  - UNIQUE KEY uq_member_achievement_date (member_id, achievement_id, awarded_date)
 *    → duplicate award handling
 *  - awarded_by is NULLable with FK to users ON DELETE SET NULL
 *    → award history survives deletion of the awarding user
 *
 * Also covers gaps left by the basic test: award history preservation
 * after definition deactivation, per-member isolation, secondary award
 * ordering, description updates, and category+inactive listing.
 */
class AchievementAwardConstraintsTest extends TestCase
{
    private Database $db;
    private AchievementService $svc;

    private int $userId;
    private int $memberA;
    private int $memberB;

    protected function setUp(): void
    {
        try {
            $this->db = new Database(TEST_CONFIG['db']);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
        foreach (['member_achievements', 'achievement_definitions', 'members', 'users'] as $t) {
            $this->db->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");

        $this->db->query("
            CREATE TABLE `users` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `email` VARCHAR(255) NOT NULL UNIQUE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->db->query("
            CREATE TABLE `members` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `membership_number` VARCHAR(20) NOT NULL,
                `first_name` VARCHAR(100) NOT NULL,
                `surname` VARCHAR(100) NOT NULL,
                `status` ENUM('active','pending','suspended','inactive','left')
                    NOT NULL DEFAULT 'active'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Matches app/migrations/0013_achievements.sql
        $this->db->query("
            CREATE TABLE `achievement_definitions` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(200) NOT NULL,
                `description` TEXT NULL,
                `category` ENUM('achievement','training') NOT NULL,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->db->query("
            CREATE TABLE `member_achievements` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `member_id` INT UNSIGNED NOT NULL,
                `achievement_id` INT UNSIGNED NOT NULL,
                `awarded_date` DATE NOT NULL,
                `awarded_by` INT UNSIGNED NULL,
                `notes` TEXT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_member_achievement_date`
                    (`member_id`, `achievement_id`, `awarded_date`),
                CONSTRAINT `fk_mac_member`
                    FOREIGN KEY (`member_id`) REFERENCES `members` (`id`)
                    ON DELETE CASCADE,
                CONSTRAINT `fk_mac_def`
                    FOREIGN KEY (`achievement_id`) REFERENCES `achievement_definitions` (`id`)
                    ON DELETE CASCADE,
                CONSTRAINT `fk_mac_awarded_by`
                    FOREIGN KEY (`awarded_by`) REFERENCES `users` (`id`)
                    ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->userId = $this->db->insert('users', ['email' => 'leader@example.com']);

        $this->memberA = $this->db->insert('members', [
            'membership_number' => 'M-100',
            'first_name' => 'Alice',
            'surname' => 'Alpha',
        ]);
        $this->memberB = $this->db->insert('members', [
            'membership_number' => 'M-200',
            'first_name' => 'Bob',
            'surname' => 'Beta',
        ]);

        $this->svc = new AchievementService($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
            foreach (['member_achievements', 'achievement_definitions', 'members', 'users'] as $t) {
                $this->db->query("DROP TABLE IF EXISTS `{$t}`");
            }
            $this->db->query("SET FOREIGN_KEY_CHECKS = 1");
        }
    }

    // ── Duplicate award handling (unique key from migration 0013) ────────

    public function testDuplicateAwardSameMemberAchievementAndDateThrows(): void
    {
        $defId = $this->svc->createDefinition(['name' => 'Badge', 'category' => 'achievement']);
        $this->svc->awardToMember($this->memberA, $defId, '2026-01-01', $this->userId);

        $this->expectException(\PDOException::class);
        $this->svc->awardToMember($this->memberA, $defId, '2026-01-01', $this->userId);
    }

    public function testSameAchievementSameDateAllowedForDifferentMembers(): void
    {
        $defId = $this->svc->createDefinition(['name' => 'Badge', 'category' => 'achievement']);
        $this->svc->awardToMember($this->memberA, $defId, '2026-01-01', $this->userId);
        $this->svc->awardToMember($this->memberB, $defId, '2026-01-01', $this->userId);

        $this->assertCount(2, $this->svc->getMembersWithAchievement($defId));
    }

    public function testSameAchievementDifferentDatesAllowedUnderUniqueKey(): void
    {
        $defId = $this->svc->createDefinition(['name' => 'Annual Course', 'category' => 'training']);
        $this->svc->awardToMember($this->memberA, $defId, '2025-06-01', $this->userId);
        $this->svc->awardToMember($this->memberA, $defId, '2026-06-01', $this->userId);

        $this->assertCount(2, $this->svc->getMemberAchievements($this->memberA));
    }

    public function testRevokedAwardCanBeReawardedOnSameDate(): void
    {
        $defId = $this->svc->createDefinition(['name' => 'Badge', 'category' => 'achievement']);
        $awardId = $this->svc->awardToMember($this->memberA, $defId, '2026-01-01', $this->userId);
        $this->svc->revokeFromMember($awardId);

        $newId = $this->svc->awardToMember($this->memberA, $defId, '2026-01-01', $this->userId);

        $this->assertGreaterThan(0, $newId);
        $this->assertCount(1, $this->svc->getMemberAchievements($this->memberA));
    }

    // ── Award history preservation ────────────────────────────────────────

    public function testAwardedBySetToNullWhenAwardingUserDeleted(): void
    {
        $defId = $this->svc->createDefinition(['name' => 'Badge', 'category' => 'achievement']);
        $this->svc->awardToMember($this->memberA, $defId, '2026-01-01', $this->userId);

        $this->db->query("DELETE FROM `users` WHERE `id` = ?", [$this->userId]);

        $awards = $this->svc->getMemberAchievements($this->memberA);
        $this->assertCount(1, $awards, 'Award history must survive deletion of the awarding user');
        $this->assertNull($awards[0]['awarded_by']);
    }

    public function testAwardsPreservedWhenDefinitionDeactivated(): void
    {
        $defId = $this->svc->createDefinition(['name' => 'Retired Badge', 'category' => 'achievement']);
        $this->svc->awardToMember($this->memberA, $defId, '2026-01-01', $this->userId);

        $this->svc->deactivateDefinition($defId);

        $awards = $this->svc->getMemberAchievements($this->memberA);
        $this->assertCount(1, $awards);
        $this->assertSame('Retired Badge', $awards[0]['achievement_name']);

        // ...but the definition no longer appears for new awards
        $names = array_column($this->svc->getDefinitions(), 'name');
        $this->assertNotContains('Retired Badge', $names);
    }

    // ── Per-member isolation and ordering ─────────────────────────────────

    public function testGetMemberAchievementsDoesNotLeakOtherMembersAwards(): void
    {
        $defId = $this->svc->createDefinition(['name' => 'Shared Badge', 'category' => 'achievement']);
        $this->svc->awardToMember($this->memberA, $defId, '2026-01-01', $this->userId);
        $this->svc->awardToMember($this->memberB, $defId, '2026-01-02', $this->userId);

        $awards = $this->svc->getMemberAchievements($this->memberA);
        $this->assertCount(1, $awards);
        $this->assertEquals($this->memberA, $awards[0]['member_id']);
    }

    public function testGetMemberAchievementsSameDateOrderedByNameAsc(): void
    {
        $zebra = $this->svc->createDefinition(['name' => 'Zebra Badge', 'category' => 'achievement']);
        $alpha = $this->svc->createDefinition(['name' => 'Alpha Badge', 'category' => 'achievement']);
        $this->svc->awardToMember($this->memberA, $zebra, '2026-01-01', $this->userId);
        $this->svc->awardToMember($this->memberA, $alpha, '2026-01-01', $this->userId);

        $names = array_column($this->svc->getMemberAchievements($this->memberA), 'achievement_name');
        $this->assertSame(['Alpha Badge', 'Zebra Badge'], $names);
    }

    public function testGetMembersWithAchievementIncludesMembershipNumber(): void
    {
        $defId = $this->svc->createDefinition(['name' => 'Badge', 'category' => 'achievement']);
        $this->svc->awardToMember($this->memberA, $defId, '2026-01-01', $this->userId);

        $holders = $this->svc->getMembersWithAchievement($defId);
        $this->assertSame('M-100', $holders[0]['membership_number']);
    }

    // ── Definition CRUD gaps ──────────────────────────────────────────────

    public function testCreateDefinitionDescriptionDefaultsToNull(): void
    {
        $id = $this->svc->createDefinition(['name' => 'Bare', 'category' => 'achievement']);
        $this->assertNull($this->svc->getDefinitionById($id)['description']);
    }

    public function testUpdateDefinitionChangesDescription(): void
    {
        $id = $this->svc->createDefinition([
            'name' => 'Badge',
            'category' => 'achievement',
            'description' => 'Old text',
        ]);

        $this->svc->updateDefinition($id, ['description' => 'New text']);

        $this->assertSame('New text', $this->svc->getDefinitionById($id)['description']);
    }

    public function testGetDefinitionsCategoryFilterCombinedWithInactive(): void
    {
        $this->svc->createDefinition(['name' => 'Active Course', 'category' => 'training', 'is_active' => 1]);
        $this->svc->createDefinition(['name' => 'Old Course', 'category' => 'training', 'is_active' => 0]);
        $this->svc->createDefinition(['name' => 'Old Badge', 'category' => 'achievement', 'is_active' => 0]);

        $rows = $this->svc->getDefinitions('training', false);
        $names = array_column($rows, 'name');
        sort($names);

        $this->assertSame(['Active Course', 'Old Course'], $names);
    }

    public function testReorderDefinitionsAcceptsStringIds(): void
    {
        $idA = $this->svc->createDefinition(['name' => 'A', 'category' => 'achievement', 'sort_order' => 0]);
        $idB = $this->svc->createDefinition(['name' => 'B', 'category' => 'achievement', 'sort_order' => 1]);

        // IDs arrive as strings from HTTP form posts
        $this->svc->reorderDefinitions([(string) $idB, (string) $idA]);

        $names = array_column($this->svc->getDefinitions('achievement'), 'name');
        $this->assertSame(['B', 'A'], $names);
    }
}

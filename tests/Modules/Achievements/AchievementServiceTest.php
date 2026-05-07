<?php

declare(strict_types=1);

namespace Tests\Modules\Achievements;

use App\Core\Database;
use App\Modules\Achievements\Services\AchievementService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AchievementService.
 *
 * Covers definition CRUD, activation/deactivation, listing with filters,
 * ordering, member award creation and revocation, and award queries.
 * Uses a real MySQL test database; each test run starts from scratch tables.
 */
class AchievementServiceTest extends TestCase
{
    private Database $db;
    private AchievementService $svc;

    /** User ID used as awarded_by throughout tests */
    private int $userId = 1;

    /** Member IDs created in setUp */
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
        foreach (['member_achievements', 'achievement_definitions', 'members'] as $t) {
            $this->db->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");

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

        $this->db->query("
            CREATE TABLE `achievement_definitions` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(200) NOT NULL,
                `description` TEXT NULL,
                `category` ENUM('achievement','training') NOT NULL DEFAULT 'achievement',
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `sort_order` INT NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->db->query("
            CREATE TABLE `member_achievements` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `member_id` INT UNSIGNED NOT NULL,
                `achievement_id` INT UNSIGNED NOT NULL,
                `awarded_date` DATE NOT NULL,
                `awarded_by` INT UNSIGNED NOT NULL,
                `notes` TEXT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT `fk_ma_member`
                    FOREIGN KEY (`member_id`) REFERENCES `members` (`id`)
                    ON DELETE CASCADE,
                CONSTRAINT `fk_ma_def`
                    FOREIGN KEY (`achievement_id`) REFERENCES `achievement_definitions` (`id`)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->memberA = $this->db->insert('members', [
            'membership_number' => 'M-001',
            'first_name' => 'Alice',
            'surname' => 'Alpha',
        ]);
        $this->memberB = $this->db->insert('members', [
            'membership_number' => 'M-002',
            'first_name' => 'Bob',
            'surname' => 'Beta',
        ]);

        $this->svc = new AchievementService($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
            foreach (['member_achievements', 'achievement_definitions', 'members'] as $t) {
                $this->db->query("DROP TABLE IF EXISTS `{$t}`");
            }
            $this->db->query("SET FOREIGN_KEY_CHECKS = 1");
        }
    }

    // ── createDefinition ────────────────────────────────────────────────

    public function testCreateDefinitionReturnsPositiveId(): void
    {
        $id = $this->svc->createDefinition(['name' => 'Bronze Award', 'category' => 'achievement']);
        $this->assertGreaterThan(0, $id);
    }

    public function testCreateDefinitionPersistsAllFields(): void
    {
        $id = $this->svc->createDefinition([
            'name' => '  First Aid  ',
            'description' => 'Basic first-aid course',
            'category' => 'training',
            'is_active' => 0,
            'sort_order' => 5,
        ]);

        $row = $this->svc->getDefinitionById($id);
        $this->assertNotNull($row);
        $this->assertSame('First Aid', $row['name'], 'name should be trimmed');
        $this->assertSame('Basic first-aid course', $row['description']);
        $this->assertSame('training', $row['category']);
        $this->assertEquals(0, $row['is_active']);
        $this->assertEquals(5, $row['sort_order']);
    }

    public function testCreateDefinitionDefaultsToActiveAndZeroOrder(): void
    {
        $id = $this->svc->createDefinition(['name' => 'Badge', 'category' => 'achievement']);
        $row = $this->svc->getDefinitionById($id);
        $this->assertEquals(1, $row['is_active']);
        $this->assertEquals(0, $row['sort_order']);
    }

    public function testCreateDefinitionThrowsWhenNameEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->createDefinition(['name' => '   ', 'category' => 'achievement']);
    }

    public function testCreateDefinitionThrowsWhenNameMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->createDefinition(['category' => 'achievement']);
    }

    public function testCreateDefinitionThrowsOnInvalidCategory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->createDefinition(['name' => 'Badge', 'category' => 'medal']);
    }

    public function testCreateDefinitionAcceptsTrainingCategory(): void
    {
        $id = $this->svc->createDefinition(['name' => 'Safety Course', 'category' => 'training']);
        $row = $this->svc->getDefinitionById($id);
        $this->assertSame('training', $row['category']);
    }

    // ── updateDefinition ────────────────────────────────────────────────

    public function testUpdateDefinitionChangesName(): void
    {
        $id = $this->svc->createDefinition(['name' => 'Old Name', 'category' => 'achievement']);
        $this->svc->updateDefinition($id, ['name' => 'New Name']);
        $row = $this->svc->getDefinitionById($id);
        $this->assertSame('New Name', $row['name']);
    }

    public function testUpdateDefinitionTrimsName(): void
    {
        $id = $this->svc->createDefinition(['name' => 'Trim Me', 'category' => 'achievement']);
        $this->svc->updateDefinition($id, ['name' => '  Trimmed  ']);
        $row = $this->svc->getDefinitionById($id);
        $this->assertSame('Trimmed', $row['name']);
    }

    public function testUpdateDefinitionThrowsOnEmptyName(): void
    {
        $id = $this->svc->createDefinition(['name' => 'Keep', 'category' => 'achievement']);
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->updateDefinition($id, ['name' => '']);
    }

    public function testUpdateDefinitionChangesCategory(): void
    {
        $id = $this->svc->createDefinition(['name' => 'Flex', 'category' => 'achievement']);
        $this->svc->updateDefinition($id, ['category' => 'training']);
        $row = $this->svc->getDefinitionById($id);
        $this->assertSame('training', $row['category']);
    }

    public function testUpdateDefinitionThrowsOnInvalidCategory(): void
    {
        $id = $this->svc->createDefinition(['name' => 'Flex', 'category' => 'achievement']);
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->updateDefinition($id, ['category' => 'badge']);
    }

    public function testUpdateDefinitionChangesIsActive(): void
    {
        $id = $this->svc->createDefinition(['name' => 'Active', 'category' => 'achievement']);
        $this->svc->updateDefinition($id, ['is_active' => 0]);
        $row = $this->svc->getDefinitionById($id);
        $this->assertEquals(0, $row['is_active']);
    }

    public function testUpdateDefinitionChangesSortOrder(): void
    {
        $id = $this->svc->createDefinition(['name' => 'Sortable', 'category' => 'achievement']);
        $this->svc->updateDefinition($id, ['sort_order' => 99]);
        $row = $this->svc->getDefinitionById($id);
        $this->assertEquals(99, $row['sort_order']);
    }

    public function testUpdateDefinitionIgnoresUnknownFields(): void
    {
        $id = $this->svc->createDefinition(['name' => 'Keep', 'category' => 'achievement']);
        // Should not throw; unknown field is silently ignored
        $this->svc->updateDefinition($id, ['nonexistent' => 'value', 'name' => 'Updated']);
        $row = $this->svc->getDefinitionById($id);
        $this->assertSame('Updated', $row['name']);
    }

    public function testUpdateDefinitionWithEmptyArrayDoesNothing(): void
    {
        $id = $this->svc->createDefinition(['name' => 'Unchanged', 'category' => 'achievement']);
        $this->svc->updateDefinition($id, []);
        $row = $this->svc->getDefinitionById($id);
        $this->assertSame('Unchanged', $row['name']);
    }

    // ── deactivateDefinition / activateDefinition ────────────────────────

    public function testDeactivateDefinitionSetsIsActiveZero(): void
    {
        $id = $this->svc->createDefinition(['name' => 'Active', 'category' => 'achievement']);
        $this->svc->deactivateDefinition($id);
        $row = $this->svc->getDefinitionById($id);
        $this->assertEquals(0, $row['is_active']);
    }

    public function testActivateDefinitionSetsIsActiveOne(): void
    {
        $id = $this->svc->createDefinition([
            'name' => 'Dormant',
            'category' => 'achievement',
            'is_active' => 0,
        ]);
        $this->svc->activateDefinition($id);
        $row = $this->svc->getDefinitionById($id);
        $this->assertEquals(1, $row['is_active']);
    }

    public function testDeactivateThenActivateRoundTrip(): void
    {
        $id = $this->svc->createDefinition(['name' => 'Toggle', 'category' => 'achievement']);
        $this->svc->deactivateDefinition($id);
        $this->assertEquals(0, $this->svc->getDefinitionById($id)['is_active']);
        $this->svc->activateDefinition($id);
        $this->assertEquals(1, $this->svc->getDefinitionById($id)['is_active']);
    }

    // ── getDefinitions ───────────────────────────────────────────────────

    public function testGetDefinitionsReturnsOnlyActiveByDefault(): void
    {
        $this->svc->createDefinition(['name' => 'Active', 'category' => 'achievement', 'is_active' => 1]);
        $this->svc->createDefinition(['name' => 'Inactive', 'category' => 'achievement', 'is_active' => 0]);

        $rows = $this->svc->getDefinitions();
        $names = array_column($rows, 'name');
        $this->assertContains('Active', $names);
        $this->assertNotContains('Inactive', $names);
    }

    public function testGetDefinitionsAllWhenActiveOnlyFalse(): void
    {
        $this->svc->createDefinition(['name' => 'Active', 'category' => 'achievement', 'is_active' => 1]);
        $this->svc->createDefinition(['name' => 'Inactive', 'category' => 'achievement', 'is_active' => 0]);

        $rows = $this->svc->getDefinitions(null, false);
        $names = array_column($rows, 'name');
        $this->assertContains('Active', $names);
        $this->assertContains('Inactive', $names);
    }

    public function testGetDefinitionsFiltersAchievements(): void
    {
        $this->svc->createDefinition(['name' => 'Gold Badge', 'category' => 'achievement']);
        $this->svc->createDefinition(['name' => 'Safety Course', 'category' => 'training']);

        $rows = $this->svc->getDefinitions('achievement');
        $categories = array_unique(array_column($rows, 'category'));
        $this->assertSame(['achievement'], $categories);
    }

    public function testGetDefinitionsFiltersTraining(): void
    {
        $this->svc->createDefinition(['name' => 'Gold Badge', 'category' => 'achievement']);
        $this->svc->createDefinition(['name' => 'Safety Course', 'category' => 'training']);

        $rows = $this->svc->getDefinitions('training');
        $categories = array_unique(array_column($rows, 'category'));
        $this->assertSame(['training'], $categories);
    }

    public function testGetDefinitionsReturnsAllCategoriesWhenNullCategory(): void
    {
        $this->svc->createDefinition(['name' => 'Ach', 'category' => 'achievement']);
        $this->svc->createDefinition(['name' => 'Trn', 'category' => 'training']);

        $rows = $this->svc->getDefinitions(null, true);
        $cats = array_unique(array_column($rows, 'category'));
        sort($cats);
        $this->assertSame(['achievement', 'training'], $cats);
    }

    public function testGetDefinitionsOrderedBySortOrderThenName(): void
    {
        $this->svc->createDefinition(['name' => 'Zebra', 'category' => 'achievement', 'sort_order' => 1]);
        $this->svc->createDefinition(['name' => 'Alpha', 'category' => 'achievement', 'sort_order' => 1]);
        $this->svc->createDefinition(['name' => 'First', 'category' => 'achievement', 'sort_order' => 0]);

        $rows = $this->svc->getDefinitions('achievement');
        $names = array_column($rows, 'name');
        $this->assertSame(['First', 'Alpha', 'Zebra'], $names);
    }

    public function testGetDefinitionsReturnsEmptyArrayWhenNone(): void
    {
        $rows = $this->svc->getDefinitions();
        $this->assertSame([], $rows);
    }

    // ── getDefinitionById ────────────────────────────────────────────────

    public function testGetDefinitionByIdReturnsCorrectRow(): void
    {
        $id = $this->svc->createDefinition(['name' => 'Named', 'category' => 'training']);
        $row = $this->svc->getDefinitionById($id);
        $this->assertSame('Named', $row['name']);
        $this->assertSame('training', $row['category']);
    }

    public function testGetDefinitionByIdReturnsNullForMissingId(): void
    {
        $result = $this->svc->getDefinitionById(999999);
        $this->assertNull($result);
    }

    // ── awardToMember ────────────────────────────────────────────────────

    public function testAwardToMemberReturnsPositiveId(): void
    {
        $defId = $this->svc->createDefinition(['name' => 'Badge', 'category' => 'achievement']);
        $id = $this->svc->awardToMember($this->memberA, $defId, '2026-01-01', $this->userId);
        $this->assertGreaterThan(0, $id);
    }

    public function testAwardToMemberPersistsAllFields(): void
    {
        $defId = $this->svc->createDefinition(['name' => 'First Aid', 'category' => 'training']);
        $awardId = $this->svc->awardToMember(
            $this->memberA,
            $defId,
            '2026-03-15',
            $this->userId,
            'Passed with distinction'
        );

        $awards = $this->svc->getMemberAchievements($this->memberA);
        $this->assertCount(1, $awards);
        $award = $awards[0];
        $this->assertEquals($awardId, $award['id']);
        $this->assertEquals($this->memberA, $award['member_id']);
        $this->assertEquals($defId, $award['achievement_id']);
        $this->assertSame('2026-03-15', $award['awarded_date']);
        $this->assertEquals($this->userId, $award['awarded_by']);
        $this->assertSame('Passed with distinction', $award['notes']);
    }

    public function testAwardToMemberWithNullNotes(): void
    {
        $defId = $this->svc->createDefinition(['name' => 'No Notes', 'category' => 'achievement']);
        $this->svc->awardToMember($this->memberA, $defId, '2026-01-01', $this->userId, null);

        $awards = $this->svc->getMemberAchievements($this->memberA);
        $this->assertCount(1, $awards);
        $this->assertNull($awards[0]['notes']);
    }

    public function testAwardSameDefinitionTwiceToSameMember(): void
    {
        $defId = $this->svc->createDefinition(['name' => 'Repeatable', 'category' => 'training']);
        $this->svc->awardToMember($this->memberA, $defId, '2025-01-01', $this->userId);
        $this->svc->awardToMember($this->memberA, $defId, '2026-01-01', $this->userId);

        $awards = $this->svc->getMemberAchievements($this->memberA);
        $this->assertCount(2, $awards);
    }

    // ── revokeFromMember ─────────────────────────────────────────────────

    public function testRevokeFromMemberRemovesRecord(): void
    {
        $defId = $this->svc->createDefinition(['name' => 'Revokable', 'category' => 'achievement']);
        $awardId = $this->svc->awardToMember($this->memberA, $defId, '2026-01-01', $this->userId);

        $this->svc->revokeFromMember($awardId);

        $awards = $this->svc->getMemberAchievements($this->memberA);
        $this->assertCount(0, $awards);
    }

    public function testRevokeFromMemberOnlyRemovesTargetRecord(): void
    {
        $defId = $this->svc->createDefinition(['name' => 'Multi', 'category' => 'achievement']);
        $firstId = $this->svc->awardToMember($this->memberA, $defId, '2025-01-01', $this->userId);
        $this->svc->awardToMember($this->memberA, $defId, '2026-01-01', $this->userId);

        $this->svc->revokeFromMember($firstId);

        $awards = $this->svc->getMemberAchievements($this->memberA);
        $this->assertCount(1, $awards);
        $this->assertNotEquals($firstId, $awards[0]['id']);
    }

    public function testRevokeNonExistentIdDoesNotThrow(): void
    {
        // Should complete silently — no exception
        $this->svc->revokeFromMember(999999);
        $this->assertTrue(true); // reached without exception
    }

    // ── getMemberAchievements ─────────────────────────────────────────────

    public function testGetMemberAchievementsReturnsEmptyForNewMember(): void
    {
        $awards = $this->svc->getMemberAchievements($this->memberA);
        $this->assertSame([], $awards);
    }

    public function testGetMemberAchievementsIncludesDefinitionName(): void
    {
        $defId = $this->svc->createDefinition(['name' => 'Gold Star', 'category' => 'achievement']);
        $this->svc->awardToMember($this->memberA, $defId, '2026-01-01', $this->userId);

        $awards = $this->svc->getMemberAchievements($this->memberA);
        $this->assertSame('Gold Star', $awards[0]['achievement_name']);
    }

    public function testGetMemberAchievementsIncludesDefinitionCategory(): void
    {
        $defId = $this->svc->createDefinition(['name' => 'Safety', 'category' => 'training']);
        $this->svc->awardToMember($this->memberA, $defId, '2026-01-01', $this->userId);

        $awards = $this->svc->getMemberAchievements($this->memberA);
        $this->assertSame('training', $awards[0]['category']);
    }

    public function testGetMemberAchievementsFiltersByCategory(): void
    {
        $achId = $this->svc->createDefinition(['name' => 'Badge', 'category' => 'achievement']);
        $trnId = $this->svc->createDefinition(['name' => 'Course', 'category' => 'training']);
        $this->svc->awardToMember($this->memberA, $achId, '2026-01-01', $this->userId);
        $this->svc->awardToMember($this->memberA, $trnId, '2026-02-01', $this->userId);

        $achievements = $this->svc->getMemberAchievements($this->memberA, 'achievement');
        $this->assertCount(1, $achievements);
        $this->assertSame('achievement', $achievements[0]['category']);

        $training = $this->svc->getMemberAchievements($this->memberA, 'training');
        $this->assertCount(1, $training);
        $this->assertSame('training', $training[0]['category']);
    }

    public function testGetMemberAchievementsOrderedByDateDesc(): void
    {
        $defId = $this->svc->createDefinition(['name' => 'Ordered', 'category' => 'achievement']);
        $this->svc->awardToMember($this->memberA, $defId, '2024-06-01', $this->userId);
        $this->svc->awardToMember($this->memberA, $defId, '2026-01-01', $this->userId);
        $this->svc->awardToMember($this->memberA, $defId, '2025-03-01', $this->userId);

        $awards = $this->svc->getMemberAchievements($this->memberA);
        $dates = array_column($awards, 'awarded_date');
        $this->assertSame(['2026-01-01', '2025-03-01', '2024-06-01'], $dates);
    }

    public function testGetMemberAchievementsReturnsNullForNoNullCategory(): void
    {
        $defId = $this->svc->createDefinition(['name' => 'Badge', 'category' => 'achievement']);
        $this->svc->awardToMember($this->memberA, $defId, '2026-01-01', $this->userId);

        // Filtering by 'training' when only 'achievement' exists returns empty
        $rows = $this->svc->getMemberAchievements($this->memberA, 'training');
        $this->assertSame([], $rows);
    }

    // ── getMembersWithAchievement ────────────────────────────────────────

    public function testGetMembersWithAchievementReturnsHolders(): void
    {
        $defId = $this->svc->createDefinition(['name' => 'Group Award', 'category' => 'achievement']);
        $this->svc->awardToMember($this->memberA, $defId, '2026-01-01', $this->userId);
        $this->svc->awardToMember($this->memberB, $defId, '2026-02-01', $this->userId);

        $holders = $this->svc->getMembersWithAchievement($defId);
        $this->assertCount(2, $holders);
    }

    public function testGetMembersWithAchievementIncludesMemberName(): void
    {
        $defId = $this->svc->createDefinition(['name' => 'Named Award', 'category' => 'achievement']);
        $this->svc->awardToMember($this->memberA, $defId, '2026-01-01', $this->userId);

        $holders = $this->svc->getMembersWithAchievement($defId);
        $this->assertArrayHasKey('first_name', $holders[0]);
        $this->assertArrayHasKey('surname', $holders[0]);
        $this->assertSame('Alice', $holders[0]['first_name']);
    }

    public function testGetMembersWithAchievementReturnsEmptyWhenNone(): void
    {
        $defId = $this->svc->createDefinition(['name' => 'Unawarded', 'category' => 'achievement']);
        $holders = $this->svc->getMembersWithAchievement($defId);
        $this->assertSame([], $holders);
    }

    public function testGetMembersWithAchievementOrderedBySurnameThenFirstName(): void
    {
        $defId = $this->svc->createDefinition(['name' => 'Sort Award', 'category' => 'achievement']);
        // memberB is Beta, memberA is Alpha — expect Alpha first
        $this->svc->awardToMember($this->memberB, $defId, '2026-01-01', $this->userId);
        $this->svc->awardToMember($this->memberA, $defId, '2026-01-01', $this->userId);

        $holders = $this->svc->getMembersWithAchievement($defId);
        $this->assertSame('Alpha', $holders[0]['surname']);
        $this->assertSame('Beta', $holders[1]['surname']);
    }

    public function testGetMembersWithAchievementOnlyForThatDefinition(): void
    {
        $def1 = $this->svc->createDefinition(['name' => 'Award 1', 'category' => 'achievement']);
        $def2 = $this->svc->createDefinition(['name' => 'Award 2', 'category' => 'achievement']);
        $this->svc->awardToMember($this->memberA, $def1, '2026-01-01', $this->userId);
        $this->svc->awardToMember($this->memberB, $def2, '2026-01-01', $this->userId);

        $holders1 = $this->svc->getMembersWithAchievement($def1);
        $this->assertCount(1, $holders1);
        $this->assertEquals($this->memberA, $holders1[0]['member_id']);

        $holders2 = $this->svc->getMembersWithAchievement($def2);
        $this->assertCount(1, $holders2);
        $this->assertEquals($this->memberB, $holders2[0]['member_id']);
    }

    // ── reorderDefinitions ───────────────────────────────────────────────

    public function testReorderDefinitionsUpdatesSortOrder(): void
    {
        $idA = $this->svc->createDefinition(['name' => 'A', 'category' => 'achievement', 'sort_order' => 0]);
        $idB = $this->svc->createDefinition(['name' => 'B', 'category' => 'achievement', 'sort_order' => 1]);
        $idC = $this->svc->createDefinition(['name' => 'C', 'category' => 'achievement', 'sort_order' => 2]);

        // Reverse order: C, A, B → positions 0, 1, 2
        $this->svc->reorderDefinitions([$idC, $idA, $idB]);

        $rows = $this->svc->getDefinitions('achievement');
        $names = array_column($rows, 'name');
        $this->assertSame(['C', 'A', 'B'], $names);
    }

    public function testReorderDefinitionsWithSingleItem(): void
    {
        $idA = $this->svc->createDefinition(['name' => 'Only', 'category' => 'achievement']);
        $this->svc->reorderDefinitions([$idA]);
        $row = $this->svc->getDefinitionById($idA);
        $this->assertEquals(0, $row['sort_order']);
    }

    public function testReorderDefinitionsWithEmptyArrayDoesNothing(): void
    {
        $idA = $this->svc->createDefinition(['name' => 'Stable', 'category' => 'achievement', 'sort_order' => 7]);
        $this->svc->reorderDefinitions([]);
        $row = $this->svc->getDefinitionById($idA);
        $this->assertEquals(7, $row['sort_order']);
    }

    // ── Cascade delete behaviour ─────────────────────────────────────────

    public function testAwardsCascadeDeleteWhenMemberIsDeleted(): void
    {
        $defId = $this->svc->createDefinition(['name' => 'Cascade', 'category' => 'achievement']);
        $this->svc->awardToMember($this->memberA, $defId, '2026-01-01', $this->userId);

        $this->db->query("DELETE FROM `members` WHERE `id` = ?", [$this->memberA]);

        $awards = $this->svc->getMemberAchievements($this->memberA);
        $this->assertSame([], $awards);
    }

    public function testAwardsCascadeDeleteWhenDefinitionIsDeleted(): void
    {
        $defId = $this->svc->createDefinition(['name' => 'Purgeable', 'category' => 'achievement']);
        $this->svc->awardToMember($this->memberA, $defId, '2026-01-01', $this->userId);

        $this->db->query("DELETE FROM `achievement_definitions` WHERE `id` = ?", [$defId]);

        $awards = $this->svc->getMemberAchievements($this->memberA);
        $this->assertSame([], $awards);
    }
}

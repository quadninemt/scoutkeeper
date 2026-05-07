<?php

declare(strict_types=1);

namespace Tests\Modules\Admin;

use App\Core\Database;
use App\Modules\Admin\Services\ReportService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ReportService.
 *
 * Covers getMemberGrowth (grouping intervals + date filters),
 * getDemographics (gender + age group breakdowns), getRolesSummary,
 * getStatusChanges, and exportMembersCsv.
 */
class ReportServiceTest extends TestCase
{
    private Database $db;
    private ReportService $service;

    /** @var array<string, int> */
    private array $nodeIds = [];

    protected function setUp(): void
    {
        try {
            $this->db = new Database(TEST_CONFIG['db']);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach ([
            'audit_log', 'role_assignment_scopes', 'role_assignments',
            'roles', 'member_nodes', 'members', 'org_closure', 'org_nodes',
        ] as $t) {
            $this->db->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');

        $this->db->query("
            CREATE TABLE `org_nodes` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(200) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->db->query("
            CREATE TABLE `org_closure` (
                `ancestor_id` INT UNSIGNED NOT NULL,
                `descendant_id` INT UNSIGNED NOT NULL,
                `depth` INT UNSIGNED NOT NULL,
                PRIMARY KEY (`ancestor_id`, `descendant_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->db->query("
            CREATE TABLE `members` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `membership_number` VARCHAR(20) NOT NULL DEFAULT '',
                `first_name` VARCHAR(100) NOT NULL DEFAULT '',
                `surname` VARCHAR(100) NOT NULL DEFAULT '',
                `email` VARCHAR(255) NULL,
                `phone` VARCHAR(50) NULL,
                `dob` DATE NULL,
                `gender` VARCHAR(20) NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'active',
                `joined_date` DATE NULL,
                `left_date` DATE NULL,
                `address_line1` VARCHAR(200) NULL,
                `address_line2` VARCHAR(200) NULL,
                `city` VARCHAR(100) NULL,
                `postcode` VARCHAR(20) NULL,
                `country` VARCHAR(100) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->db->query("
            CREATE TABLE `member_nodes` (
                `member_id` INT UNSIGNED NOT NULL,
                `node_id` INT UNSIGNED NOT NULL,
                `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (`member_id`, `node_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->db->query("
            CREATE TABLE `roles` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->db->query("
            CREATE TABLE `role_assignments` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `role_id` INT UNSIGNED NOT NULL,
                `member_id` INT UNSIGNED NULL,
                `context_type` VARCHAR(50) NULL,
                `context_id` INT UNSIGNED NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->db->query("
            CREATE TABLE `audit_log` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NULL,
                `action` VARCHAR(100) NOT NULL,
                `entity_type` VARCHAR(100) NOT NULL,
                `entity_id` INT UNSIGNED NULL,
                `old_values` JSON NULL,
                `new_values` JSON NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Seed an org node
        $nodeId = $this->db->insert('org_nodes', ['name' => 'Group 1']);
        $this->nodeIds['group1'] = $nodeId;
        $this->db->insert('org_closure', ['ancestor_id' => $nodeId, 'descendant_id' => $nodeId, 'depth' => 0]);

        $this->service = new ReportService($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
            foreach ([
                'audit_log', 'role_assignments', 'roles',
                'member_nodes', 'members', 'org_closure', 'org_nodes',
            ] as $t) {
                $this->db->query("DROP TABLE IF EXISTS `{$t}`");
            }
            $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    // ── getMemberGrowth() ────────────────────────────────────────────────

    public function testGetMemberGrowthReturnsEmptyWhenNoMembers(): void
    {
        $result = $this->service->getMemberGrowth('month');
        $this->assertSame([], $result);
    }

    public function testGetMemberGrowthGroupsByMonth(): void
    {
        $this->insertMember(['joined_date' => '2024-03-15']);
        $this->insertMember(['joined_date' => '2024-03-22']);
        $this->insertMember(['joined_date' => '2024-04-01']);

        $result = $this->service->getMemberGrowth('month');

        $periods = array_column($result, 'period');
        $this->assertContains('2024-03', $periods);
        $this->assertContains('2024-04', $periods);

        $march = array_values(array_filter($result, fn($r) => $r['period'] === '2024-03'))[0];
        $this->assertSame(2, $march['count']);
    }

    public function testGetMemberGrowthGroupsByYear(): void
    {
        $this->insertMember(['joined_date' => '2023-06-01']);
        $this->insertMember(['joined_date' => '2024-01-15']);
        $this->insertMember(['joined_date' => '2024-07-20']);

        $result = $this->service->getMemberGrowth('year');

        $periods = array_column($result, 'period');
        $this->assertContains('2023', $periods);
        $this->assertContains('2024', $periods);

        $year2024 = array_values(array_filter($result, fn($r) => $r['period'] === '2024'))[0];
        $this->assertSame(2, $year2024['count']);
    }

    public function testGetMemberGrowthGroupsByQuarter(): void
    {
        $this->insertMember(['joined_date' => '2024-01-10']); // Q1
        $this->insertMember(['joined_date' => '2024-04-05']); // Q2
        $this->insertMember(['joined_date' => '2024-05-20']); // Q2

        $result = $this->service->getMemberGrowth('quarter');

        $periods = array_column($result, 'period');
        $this->assertContains('2024-Q1', $periods);
        $this->assertContains('2024-Q2', $periods);
    }

    public function testGetMemberGrowthFiltersbyStartDate(): void
    {
        $this->insertMember(['joined_date' => '2023-01-01']);
        $this->insertMember(['joined_date' => '2024-06-01']);

        $result = $this->service->getMemberGrowth('year', '2024-01-01');

        $periods = array_column($result, 'period');
        $this->assertNotContains('2023', $periods);
        $this->assertContains('2024', $periods);
    }

    public function testGetMemberGrowthFiltersByEndDate(): void
    {
        $this->insertMember(['joined_date' => '2023-01-01']);
        $this->insertMember(['joined_date' => '2024-06-01']);

        $result = $this->service->getMemberGrowth('year', null, '2023-12-31');

        $periods = array_column($result, 'period');
        $this->assertContains('2023', $periods);
        $this->assertNotContains('2024', $periods);
    }

    public function testGetMemberGrowthExcludesMembersWithoutJoinedDate(): void
    {
        $this->insertMember(['joined_date' => null]);
        $this->insertMember(['joined_date' => '2024-03-01']);

        $result = $this->service->getMemberGrowth('month');

        $counts = array_sum(array_column($result, 'count'));
        $this->assertSame(1, $counts);
    }

    public function testGetMemberGrowthThrowsForInvalidInterval(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->getMemberGrowth('week');
    }

    public function testGetMemberGrowthResultsAreChronological(): void
    {
        $this->insertMember(['joined_date' => '2024-06-01']);
        $this->insertMember(['joined_date' => '2024-01-01']);

        $result = $this->service->getMemberGrowth('month');

        $periods = array_column($result, 'period');
        $sorted = $periods;
        sort($sorted);
        $this->assertSame($sorted, $periods);
    }

    public function testGetMemberGrowthCountsAreCastToInt(): void
    {
        $this->insertMember(['joined_date' => '2024-01-01']);

        $result = $this->service->getMemberGrowth('month');
        $this->assertIsInt($result[0]['count']);
    }

    // ── getDemographics() ────────────────────────────────────────────────

    public function testGetDemographicsReturnsByGenderAndByAgeGroup(): void
    {
        $result = $this->service->getDemographics();

        $this->assertArrayHasKey('by_gender', $result);
        $this->assertArrayHasKey('by_age_group', $result);
    }

    public function testGetDemographicsGenderBreakdown(): void
    {
        $this->insertMember(['gender' => 'male']);
        $this->insertMember(['gender' => 'male']);
        $this->insertMember(['gender' => 'female']);

        $result = $this->service->getDemographics();
        $byGender = $result['by_gender'];

        $maleEntry = array_values(array_filter($byGender, fn($g) => $g['gender'] === 'male'))[0] ?? null;
        $femaleEntry = array_values(array_filter($byGender, fn($g) => $g['gender'] === 'female'))[0] ?? null;

        $this->assertNotNull($maleEntry);
        $this->assertSame(2, $maleEntry['count']);
        $this->assertNotNull($femaleEntry);
        $this->assertSame(1, $femaleEntry['count']);
    }

    public function testGetDemographicsNullGenderReportedAsUnknown(): void
    {
        $this->insertMember(['gender' => null]);

        $result = $this->service->getDemographics();
        $byGender = $result['by_gender'];

        $unknownEntry = array_values(array_filter($byGender, fn($g) => $g['gender'] === 'Unknown'))[0] ?? null;
        $this->assertNotNull($unknownEntry);
        $this->assertSame(1, $unknownEntry['count']);
    }

    public function testGetDemographicsAgeGroupBreakdown(): void
    {
        // Member born ~10 years ago → '8-11' bracket
        $dob = date('Y-m-d', strtotime('-10 years'));
        $this->insertMember(['dob' => $dob]);

        $result = $this->service->getDemographics();
        $byAge = $result['by_age_group'];

        $ageEntry = array_values(array_filter($byAge, fn($g) => $g['group'] === '8-11'))[0] ?? null;
        $this->assertNotNull($ageEntry);
        $this->assertGreaterThanOrEqual(1, $ageEntry['count']);
    }

    public function testGetDemographicsNullDobReportedAsUnknown(): void
    {
        $this->insertMember(['dob' => null]);

        $result = $this->service->getDemographics();
        $byAge = $result['by_age_group'];

        $unknownEntry = array_values(array_filter($byAge, fn($g) => $g['group'] === 'Unknown'))[0] ?? null;
        $this->assertNotNull($unknownEntry);
    }

    // ── getRolesSummary() ────────────────────────────────────────────────

    public function testGetRolesSummaryReturnsEmptyWhenNoAssignments(): void
    {
        $this->assertSame([], $this->service->getRolesSummary());
    }

    public function testGetRolesSummaryCountsAssignmentsByRole(): void
    {
        $roleId = $this->db->insert('roles', ['name' => 'Leader']);
        $m1 = $this->insertMember();
        $m2 = $this->insertMember();
        $m3 = $this->insertMember();

        $this->db->insert('role_assignments', ['role_id' => $roleId, 'member_id' => $m1]);
        $this->db->insert('role_assignments', ['role_id' => $roleId, 'member_id' => $m2]);
        $this->db->insert('role_assignments', ['role_id' => $roleId, 'member_id' => $m3]);

        $summary = $this->service->getRolesSummary();

        $leaderEntry = array_values(array_filter($summary, fn($r) => $r['role'] === 'Leader'))[0] ?? null;
        $this->assertNotNull($leaderEntry);
        $this->assertSame(3, $leaderEntry['count']);
    }

    public function testGetRolesSummaryCountsAreCastToInt(): void
    {
        $roleId = $this->db->insert('roles', ['name' => 'Helper']);
        $m1 = $this->insertMember();
        $this->db->insert('role_assignments', ['role_id' => $roleId, 'member_id' => $m1]);

        $summary = $this->service->getRolesSummary();
        $this->assertIsInt($summary[0]['count']);
    }

    // ── getStatusChanges() ───────────────────────────────────────────────

    public function testGetStatusChangesReturnsEmptyWhenNoAuditEntries(): void
    {
        $this->assertSame([], $this->service->getStatusChanges());
    }

    public function testGetStatusChangesReturnsStatusChangeActions(): void
    {
        $memberId = $this->insertMember();
        $this->db->query(
            "INSERT INTO `audit_log` (`action`, `entity_type`, `entity_id`, `old_values`, `new_values`, `created_at`)
             VALUES (?, ?, ?, ?, ?, NOW())",
            [
                'status_change', 'member', $memberId,
                json_encode(['status' => 'active']),
                json_encode(['status' => 'suspended']),
            ]
        );

        $changes = $this->service->getStatusChanges();

        $this->assertCount(1, $changes);
        $this->assertSame($memberId, (int) $changes[0]['member_id']);
    }

    public function testGetStatusChangesIgnoresNonStatusChangeActions(): void
    {
        $memberId = $this->insertMember();
        $this->db->query(
            "INSERT INTO `audit_log` (`action`, `entity_type`, `entity_id`, `created_at`)
             VALUES ('update', 'member', ?, NOW())",
            [$memberId]
        );

        $changes = $this->service->getStatusChanges();
        $this->assertSame([], $changes);
    }

    public function testGetStatusChangesFiltersbyStartDate(): void
    {
        $memberId = $this->insertMember();
        $this->db->query(
            "INSERT INTO `audit_log` (`action`, `entity_type`, `entity_id`, `created_at`) VALUES (?, ?, ?, ?)",
            ['status_change', 'member', $memberId, '2020-01-01 10:00:00']
        );

        $changes = $this->service->getStatusChanges('2024-01-01');
        $this->assertSame([], $changes);
    }

    public function testGetStatusChangesFiltersByEndDate(): void
    {
        $memberId = $this->insertMember();
        $this->db->query(
            "INSERT INTO `audit_log` (`action`, `entity_type`, `entity_id`, `created_at`) VALUES (?, ?, ?, ?)",
            ['status_change', 'member', $memberId, '2030-01-01 10:00:00']
        );

        $changes = $this->service->getStatusChanges(null, '2025-01-01');
        $this->assertSame([], $changes);
    }

    // ── exportMembersCsv() ───────────────────────────────────────────────

    public function testExportMembersCsvReturnsString(): void
    {
        $csv = $this->service->exportMembersCsv();
        $this->assertIsString($csv);
    }

    public function testExportMembersCsvContainsHeaderRow(): void
    {
        $csv = $this->service->exportMembersCsv();
        $this->assertStringContainsString('Membership Number', $csv);
        $this->assertStringContainsString('First Name', $csv);
        $this->assertStringContainsString('Surname', $csv);
    }

    public function testExportMembersCsvContainsMemberData(): void
    {
        $this->insertMember([
            'membership_number' => 'SK-001',
            'first_name'        => 'Alice',
            'surname'           => 'Archer',
        ]);

        $csv = $this->service->exportMembersCsv();

        $this->assertStringContainsString('SK-001', $csv);
        $this->assertStringContainsString('Alice', $csv);
        $this->assertStringContainsString('Archer', $csv);
    }

    public function testExportMembersCsvScopesToNodeIds(): void
    {
        // Member in the node
        $nodeId = $this->nodeIds['group1'];
        $inNode = $this->insertMember(['first_name' => 'InScope', 'membership_number' => 'IN-001']);
        $this->db->insert('member_nodes', ['member_id' => $inNode, 'node_id' => $nodeId, 'is_primary' => 1]);

        // Member outside any node
        $this->insertMember(['first_name' => 'OutScope', 'membership_number' => 'OUT-001']);

        $csv = $this->service->exportMembersCsv([$nodeId]);

        $this->assertStringContainsString('InScope', $csv);
        $this->assertStringNotContainsString('OutScope', $csv);
    }

    public function testExportMembersCsvWithNullNodeIdsReturnsAll(): void
    {
        $this->insertMember(['first_name' => 'Alice', 'membership_number' => 'A-001']);
        $this->insertMember(['first_name' => 'Bob', 'membership_number' => 'B-001']);

        $csv = $this->service->exportMembersCsv(null);

        $this->assertStringContainsString('Alice', $csv);
        $this->assertStringContainsString('Bob', $csv);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private int $memberSeq = 0;

    private function insertMember(array $overrides = []): int
    {
        $this->memberSeq++;
        $seq = $this->memberSeq;
        $defaults = [
            'membership_number' => 'M-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
            'first_name'        => 'Member',
            'surname'           => 'Test' . $seq,
            'status'            => 'active',
            'joined_date'       => null,
            'dob'               => null,
            'gender'            => null,
        ];
        return $this->db->insert('members', array_merge($defaults, $overrides));
    }
}

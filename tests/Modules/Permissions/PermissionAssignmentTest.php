<?php

declare(strict_types=1);

namespace Tests\Modules\Permissions;

use App\Core\Database;
use PHPUnit\Framework\TestCase;

/**
 * Role assignment and scoping tests for the Permissions module.
 *
 * Covers the data layer behaviour exercised by AssignmentsController:
 *  - Assigning a role to a user (with and without scope nodes)
 *  - Listing active vs historical assignments
 *  - Ending an assignment (soft-delete via end_date)
 *  - Scope node attachment and retrieval
 *  - Context types (node / team)
 *  - Multiple simultaneous assignments per user
 *  - FK enforcement for missing user/role
 *  - Cascade deletes on user or role removal
 *  - Edge cases: future end_date, end_date = today, all-expired user
 *
 * PermissionResolver union logic is NOT re-tested here — see
 * tests/Core/PermissionResolverTest.php.
 */
class PermissionAssignmentTest extends TestCase
{
    private ?Database $db = null;

    protected function setUp(): void
    {
        try {
            $this->db = new Database(TEST_CONFIG['db']);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['role_assignment_scopes', 'role_assignments', 'roles', 'users'] as $t) {
            $this->db->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');

        $this->db->query("
            CREATE TABLE `users` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `email` VARCHAR(255) NOT NULL UNIQUE,
                `password_hash` VARCHAR(255) NOT NULL DEFAULT '',
                `is_super_admin` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE `roles` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL UNIQUE,
                `description` VARCHAR(500) NULL,
                `permissions` JSON NOT NULL DEFAULT ('{}'),
                `can_publish_events` TINYINT(1) NOT NULL DEFAULT 0,
                `can_access_medical` TINYINT(1) NOT NULL DEFAULT 0,
                `can_access_financial` TINYINT(1) NOT NULL DEFAULT 0,
                `is_system` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE `role_assignments` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NOT NULL,
                `role_id` INT UNSIGNED NOT NULL,
                `context_type` ENUM('node','team') NULL,
                `context_id` INT UNSIGNED NULL,
                `start_date` DATE NOT NULL,
                `end_date` DATE NULL,
                `assigned_by` INT UNSIGNED NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT `fk_pat_ra_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_pat_ra_role` FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE `role_assignment_scopes` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `assignment_id` INT UNSIGNED NOT NULL,
                `node_id` INT UNSIGNED NOT NULL,
                CONSTRAINT `fk_pat_scope_asgn` FOREIGN KEY (`assignment_id`) REFERENCES `role_assignments`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    protected function tearDown(): void
    {
        if ($this->db === null) {
            return;
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['role_assignment_scopes', 'role_assignments', 'roles', 'users'] as $t) {
            $this->db->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    // ── Insert helpers ─────────────────────────────────────────────────

    private function insertUser(): int
    {
        return $this->db->insert('users', [
            'email' => 'user_' . uniqid() . '@example.com',
            'password_hash' => 'dummy',
        ]);
    }

    private function insertRole(string $name, array $permissions = []): int
    {
        return $this->db->insert('roles', [
            'name' => $name,
            'permissions' => json_encode($permissions),
        ]);
    }

    /**
     * @param array<int> $scopeNodeIds
     */
    private function insertAssignment(
        int $userId,
        int $roleId,
        ?string $endDate = null,
        ?string $contextType = null,
        ?int $contextId = null,
        array $scopeNodeIds = [],
        ?int $assignedBy = null
    ): int {
        $assignmentId = $this->db->insert('role_assignments', [
            'user_id' => $userId,
            'role_id' => $roleId,
            'start_date' => gmdate('Y-m-d'),
            'end_date' => $endDate,
            'context_type' => $contextType,
            'context_id' => $contextId,
            'assigned_by' => $assignedBy,
        ]);

        foreach ($scopeNodeIds as $nodeId) {
            $this->db->insert('role_assignment_scopes', [
                'assignment_id' => $assignmentId,
                'node_id' => (int) $nodeId,
            ]);
        }

        return $assignmentId;
    }

    // ── Basic assignment creation ──────────────────────────────────────

    public function testAssignRoleToUserCreatesRow(): void
    {
        $id = $this->insertAssignment($this->insertUser(), $this->insertRole('Leader'));
        $this->assertGreaterThan(0, $id);
    }

    public function testAssignmentStoresCorrectUserAndRole(): void
    {
        $userId = $this->insertUser();
        $roleId = $this->insertRole('Editor');
        $id = $this->insertAssignment($userId, $roleId);

        $row = $this->db->fetchOne('SELECT * FROM role_assignments WHERE id = :id', ['id' => $id]);

        $this->assertNotNull($row);
        $this->assertSame($userId, (int) $row['user_id']);
        $this->assertSame($roleId, (int) $row['role_id']);
        $this->assertNull($row['end_date']);
    }

    // ── Context types ──────────────────────────────────────────────────

    public function testAssignmentWithNodeContext(): void
    {
        $id = $this->insertAssignment($this->insertUser(), $this->insertRole('Node Role'), null, 'node', 42);
        $row = $this->db->fetchOne('SELECT context_type, context_id FROM role_assignments WHERE id = :id', ['id' => $id]);

        $this->assertSame('node', $row['context_type']);
        $this->assertSame(42, (int) $row['context_id']);
    }

    public function testAssignmentWithTeamContext(): void
    {
        $id = $this->insertAssignment($this->insertUser(), $this->insertRole('Team Role'), null, 'team', 7);
        $row = $this->db->fetchOne('SELECT context_type, context_id FROM role_assignments WHERE id = :id', ['id' => $id]);

        $this->assertSame('team', $row['context_type']);
        $this->assertSame(7, (int) $row['context_id']);
    }

    public function testInvalidContextTypeIsRejectedBySchema(): void
    {
        $userId = $this->insertUser();
        $roleId = $this->insertRole('Schema Test');

        $this->expectException(\PDOException::class);
        $this->db->insert('role_assignments', [
            'user_id' => $userId,
            'role_id' => $roleId,
            'start_date' => gmdate('Y-m-d'),
            'context_type' => 'invalid_type',
        ]);
    }

    // ── Scope nodes ────────────────────────────────────────────────────

    public function testAssignmentWithScopesStoresScopeRows(): void
    {
        $id = $this->insertAssignment($this->insertUser(), $this->insertRole('Scoped Role'), null, null, null, [10, 20, 30]);

        $scopes = $this->db->fetchAll(
            'SELECT node_id FROM role_assignment_scopes WHERE assignment_id = :id ORDER BY node_id',
            ['id' => $id]
        );

        $this->assertCount(3, $scopes);
        $this->assertSame([10, 20, 30], array_map(fn($r) => (int) $r['node_id'], $scopes));
    }

    public function testAssignmentWithNoScopesHasNoScopeRows(): void
    {
        $id = $this->insertAssignment($this->insertUser(), $this->insertRole('Unscoped'));

        $scopes = $this->db->fetchAll(
            'SELECT id FROM role_assignment_scopes WHERE assignment_id = :id',
            ['id' => $id]
        );

        $this->assertEmpty($scopes);
    }

    public function testScopeNodesLinkedToCorrectAssignment(): void
    {
        $userId = $this->insertUser();
        $roleId = $this->insertRole('Multi Scope');

        $id1 = $this->insertAssignment($userId, $roleId, null, null, null, [1, 2]);
        $id2 = $this->insertAssignment($userId, $roleId, null, null, null, [3, 4]);

        $nodes1 = array_map(
            fn($r) => (int) $r['node_id'],
            $this->db->fetchAll('SELECT node_id FROM role_assignment_scopes WHERE assignment_id = :id ORDER BY node_id', ['id' => $id1])
        );
        $nodes2 = array_map(
            fn($r) => (int) $r['node_id'],
            $this->db->fetchAll('SELECT node_id FROM role_assignment_scopes WHERE assignment_id = :id ORDER BY node_id', ['id' => $id2])
        );

        $this->assertSame([1, 2], $nodes1);
        $this->assertSame([3, 4], $nodes2);
    }

    // ── Active / history filtering ─────────────────────────────────────

    public function testActiveQueryExcludesExpiredAssignments(): void
    {
        $userId = $this->insertUser();
        $roleId = $this->insertRole('Filtered Role');

        $this->insertAssignment($userId, $roleId);               // active
        $this->insertAssignment($userId, $roleId, '2020-01-01'); // expired

        $active = $this->db->fetchAll(
            "SELECT id FROM role_assignments
             WHERE user_id = :uid AND (end_date IS NULL OR end_date >= CURDATE())",
            ['uid' => $userId]
        );

        $this->assertCount(1, $active);
    }

    public function testFutureEndDateIsConsideredActive(): void
    {
        $userId = $this->insertUser();
        $this->insertAssignment($userId, $this->insertRole('Future'), '2099-12-31');

        $active = $this->db->fetchAll(
            "SELECT id FROM role_assignments
             WHERE user_id = :uid AND (end_date IS NULL OR end_date >= CURDATE())",
            ['uid' => $userId]
        );

        $this->assertCount(1, $active);
    }

    public function testAssignmentEndingTodayIsStillActive(): void
    {
        $userId = $this->insertUser();
        $this->insertAssignment($userId, $this->insertRole('Today End'), gmdate('Y-m-d'));

        $active = $this->db->fetchAll(
            "SELECT id FROM role_assignments
             WHERE user_id = :uid AND (end_date IS NULL OR end_date >= CURDATE())",
            ['uid' => $userId]
        );

        $this->assertCount(1, $active, 'Assignment ending today must still be active (>= CURDATE)');
    }

    public function testHistoryQueryIncludesExpiredAssignments(): void
    {
        $userId = $this->insertUser();
        $roleId = $this->insertRole('History Role');
        $this->insertAssignment($userId, $roleId);
        $this->insertAssignment($userId, $roleId, '2020-01-01');

        $all = $this->db->fetchAll('SELECT id FROM role_assignments WHERE user_id = :uid', ['uid' => $userId]);
        $this->assertCount(2, $all);
    }

    public function testListAssignmentsJoinsRoleName(): void
    {
        $userId = $this->insertUser();
        $this->insertAssignment($userId, $this->insertRole('Section Leader'));

        $rows = $this->db->fetchAll(
            "SELECT r.name AS role_name
             FROM role_assignments ra JOIN roles r ON r.id = ra.role_id
             WHERE ra.user_id = :uid AND (ra.end_date IS NULL OR ra.end_date >= CURDATE())",
            ['uid' => $userId]
        );

        $this->assertNotEmpty($rows);
        $this->assertSame('Section Leader', $rows[0]['role_name']);
    }

    // ── Multiple assignments per user ──────────────────────────────────

    public function testUserCanHoldMultipleSimultaneousRoles(): void
    {
        $userId = $this->insertUser();

        $this->insertAssignment($userId, $this->insertRole('Role A'));
        $this->insertAssignment($userId, $this->insertRole('Role B'));
        $this->insertAssignment($userId, $this->insertRole('Role C'));

        $active = $this->db->fetchAll(
            "SELECT id FROM role_assignments
             WHERE user_id = :uid AND (end_date IS NULL OR end_date >= CURDATE())",
            ['uid' => $userId]
        );

        $this->assertCount(3, $active);
    }

    public function testAllAssignmentsExpiredMeansNoActiveRoles(): void
    {
        $userId = $this->insertUser();
        $this->insertAssignment($userId, $this->insertRole('Past A'), '2020-01-01');
        $this->insertAssignment($userId, $this->insertRole('Past B'), '2021-06-30');

        $active = $this->db->fetchAll(
            "SELECT id FROM role_assignments
             WHERE user_id = :uid AND (end_date IS NULL OR end_date >= CURDATE())",
            ['uid' => $userId]
        );

        $this->assertEmpty($active);
    }

    // ── Ending assignments (soft delete) ──────────────────────────────

    public function testEndAssignmentSetsEndDateToToday(): void
    {
        $userId = $this->insertUser();
        $id = $this->insertAssignment($userId, $this->insertRole('Ending Role'));
        $today = gmdate('Y-m-d');

        $this->db->update('role_assignments', ['end_date' => $today], ['id' => $id]);

        $row = $this->db->fetchOne('SELECT end_date FROM role_assignments WHERE id = :id', ['id' => $id]);
        $this->assertSame($today, $row['end_date']);
    }

    public function testEndedAssignmentNoLongerActiveAfterYesterday(): void
    {
        $userId = $this->insertUser();
        $id = $this->insertAssignment($userId, $this->insertRole('Now Ended'));
        $yesterday = gmdate('Y-m-d', strtotime('-1 day'));

        $this->db->update('role_assignments', ['end_date' => $yesterday], ['id' => $id]);

        $active = $this->db->fetchAll(
            "SELECT id FROM role_assignments
             WHERE user_id = :uid AND (end_date IS NULL OR end_date >= CURDATE())",
            ['uid' => $userId]
        );

        $this->assertEmpty($active);
    }

    public function testEndAssignmentDoesNotHardDeleteRow(): void
    {
        $userId = $this->insertUser();
        $id = $this->insertAssignment($userId, $this->insertRole('Soft Delete'));

        $this->db->update('role_assignments', ['end_date' => gmdate('Y-m-d')], ['id' => $id]);

        $this->assertNotNull(
            $this->db->fetchOne('SELECT id FROM role_assignments WHERE id = :id', ['id' => $id])
        );
    }

    // ── Foreign key enforcement ────────────────────────────────────────

    public function testAssignmentForNonExistentUserThrows(): void
    {
        $this->expectException(\PDOException::class);
        $this->db->insert('role_assignments', [
            'user_id' => 99999,
            'role_id' => $this->insertRole('FK Test'),
            'start_date' => gmdate('Y-m-d'),
        ]);
    }

    public function testAssignmentForNonExistentRoleThrows(): void
    {
        $this->expectException(\PDOException::class);
        $this->db->insert('role_assignments', [
            'user_id' => $this->insertUser(),
            'role_id' => 99999,
            'start_date' => gmdate('Y-m-d'),
        ]);
    }

    // ── Cascade deletes ────────────────────────────────────────────────

    public function testDeletingUserCascadesAssignments(): void
    {
        $userId = $this->insertUser();
        $this->insertAssignment($userId, $this->insertRole('Cascade User'));

        $this->db->delete('users', ['id' => $userId]);

        $this->assertEmpty(
            $this->db->fetchAll('SELECT id FROM role_assignments WHERE user_id = :uid', ['uid' => $userId])
        );
    }

    public function testDeletingRoleCascadesAssignments(): void
    {
        $userId = $this->insertUser();
        $roleId = $this->insertRole('Cascade Role');
        $this->insertAssignment($userId, $roleId);

        $this->db->delete('roles', ['id' => $roleId]);

        $this->assertEmpty(
            $this->db->fetchAll('SELECT id FROM role_assignments WHERE role_id = :rid', ['rid' => $roleId])
        );
    }

    public function testDeletingAssignmentCascadesScopeRows(): void
    {
        $userId = $this->insertUser();
        $roleId = $this->insertRole('Scope Cascade');
        $assignmentId = $this->insertAssignment($userId, $roleId, null, null, null, [10, 20]);

        $this->db->delete('role_assignments', ['id' => $assignmentId]);

        $this->assertEmpty(
            $this->db->fetchAll(
                'SELECT id FROM role_assignment_scopes WHERE assignment_id = :id',
                ['id' => $assignmentId]
            )
        );
    }

    // ── Scope edge cases ──────────────────────────────────────────────

    public function testActiveQueryIsolatedToCorrectUser(): void
    {
        $userId1 = $this->insertUser();
        $userId2 = $this->insertUser();
        $roleId = $this->insertRole('Shared');

        $this->insertAssignment($userId1, $roleId);
        $this->insertAssignment($userId2, $roleId);

        $active = $this->db->fetchAll(
            "SELECT id FROM role_assignments WHERE user_id = :uid AND (end_date IS NULL OR end_date >= CURDATE())",
            ['uid' => $userId1]
        );

        $this->assertCount(1, $active, 'Only assignments for the queried user must be returned');
    }
}

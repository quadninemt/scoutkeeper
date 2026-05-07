<?php

declare(strict_types=1);

namespace Tests\Modules\Permissions;

use App\Core\Database;
use PHPUnit\Framework\TestCase;

/**
 * Role management tests for the Permissions module.
 *
 * Exercises the database behaviour that the RolesController delegates to
 * the Database wrapper directly (no separate Service class exists).
 *
 * Business rules under test:
 *  - Role names are unique (UNIQUE constraint + friendly duplicate check)
 *  - System roles cannot be deleted (is_system flag detection)
 *  - Roles with active assignments cannot be deleted
 *  - Permissions are stored as JSON and round-trip correctly
 *  - Special flags (can_publish_events, can_access_medical, can_access_financial)
 *  - Role updates do not clobber is_system flag
 *  - Role listing includes active assignment counts
 *  - Cascade deletes propagate from roles to assignments to scopes
 */
class RoleServiceTest extends TestCase
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
                CONSTRAINT `fk_rst_ra_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_rst_ra_role` FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE `role_assignment_scopes` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `assignment_id` INT UNSIGNED NOT NULL,
                `node_id` INT UNSIGNED NOT NULL,
                CONSTRAINT `fk_rst_scope_asgn` FOREIGN KEY (`assignment_id`) REFERENCES `role_assignments`(`id`) ON DELETE CASCADE
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

    private function insertRole(
        string $name,
        array $permissions = [],
        bool $isSystem = false,
        bool $canPublishEvents = false,
        bool $canAccessMedical = false,
        bool $canAccessFinancial = false,
        ?string $description = null
    ): int {
        return $this->db->insert('roles', [
            'name' => $name,
            'description' => $description,
            'permissions' => json_encode($permissions),
            'is_system' => $isSystem ? 1 : 0,
            'can_publish_events' => $canPublishEvents ? 1 : 0,
            'can_access_medical' => $canAccessMedical ? 1 : 0,
            'can_access_financial' => $canAccessFinancial ? 1 : 0,
        ]);
    }

    private function insertUser(): int
    {
        return $this->db->insert('users', [
            'email' => 'user_' . uniqid() . '@example.com',
            'password_hash' => 'dummy',
        ]);
    }

    private function insertAssignment(int $userId, int $roleId, ?string $endDate = null): int
    {
        return $this->db->insert('role_assignments', [
            'user_id' => $userId,
            'role_id' => $roleId,
            'start_date' => '2026-01-01',
            'end_date' => $endDate,
        ]);
    }

    // ── Role creation ──────────────────────────────────────────────────

    public function testInsertRoleReturnsPositiveId(): void
    {
        $id = $this->insertRole('Section Leader');
        $this->assertGreaterThan(0, $id);
    }

    public function testInsertedRoleIsRetrievableById(): void
    {
        $id = $this->insertRole('Group Leader', [], false, false, false, false, 'Manages a scout group');
        $row = $this->db->fetchOne('SELECT * FROM roles WHERE id = :id', ['id' => $id]);

        $this->assertNotNull($row);
        $this->assertSame('Group Leader', $row['name']);
        $this->assertSame('Manages a scout group', $row['description']);
        $this->assertSame(0, (int) $row['is_system']);
    }

    public function testRoleDefaultFlagsAreFalse(): void
    {
        $id = $this->insertRole('Plain Role');
        $row = $this->db->fetchOne('SELECT * FROM roles WHERE id = :id', ['id' => $id]);

        $this->assertSame(0, (int) $row['can_publish_events']);
        $this->assertSame(0, (int) $row['can_access_medical']);
        $this->assertSame(0, (int) $row['can_access_financial']);
    }

    public function testRoleSpecialFlagsStoredCorrectly(): void
    {
        $id = $this->insertRole(
            'Full Access',
            [],
            false,
            canPublishEvents: true,
            canAccessMedical: true,
            canAccessFinancial: true
        );
        $row = $this->db->fetchOne('SELECT * FROM roles WHERE id = :id', ['id' => $id]);

        $this->assertSame(1, (int) $row['can_publish_events']);
        $this->assertSame(1, (int) $row['can_access_medical']);
        $this->assertSame(1, (int) $row['can_access_financial']);
    }

    // ── Permissions JSON round-trip ─────────────────────────────────────

    public function testPermissionsRoundTripAsJson(): void
    {
        $perms = ['members.read' => true, 'members.write' => true, 'events.read' => true];
        $id = $this->insertRole('Editor', $perms);

        $row = $this->db->fetchOne('SELECT permissions FROM roles WHERE id = :id', ['id' => $id]);
        $decoded = json_decode($row['permissions'], true);

        $this->assertSame($perms, $decoded);
    }

    public function testEmptyPermissionsStoredAsEmptyObject(): void
    {
        $id = $this->insertRole('No Perms', []);
        $row = $this->db->fetchOne('SELECT permissions FROM roles WHERE id = :id', ['id' => $id]);

        $decoded = json_decode($row['permissions'], true);
        $this->assertIsArray($decoded);
        $this->assertEmpty($decoded);
    }

    public function testTwoRolesHaveIndependentPermissions(): void
    {
        $id1 = $this->insertRole('Role One', ['members.read' => true]);
        $id2 = $this->insertRole('Role Two', ['events.write' => true]);

        $r1 = json_decode(
            $this->db->fetchOne('SELECT permissions FROM roles WHERE id = :id', ['id' => $id1])['permissions'],
            true
        );
        $r2 = json_decode(
            $this->db->fetchOne('SELECT permissions FROM roles WHERE id = :id', ['id' => $id2])['permissions'],
            true
        );

        $this->assertArrayHasKey('members.read', $r1);
        $this->assertArrayNotHasKey('events.write', $r1);
        $this->assertArrayHasKey('events.write', $r2);
        $this->assertArrayNotHasKey('members.read', $r2);
    }

    // ── Unique name constraint ──────────────────────────────────────────

    public function testDuplicateRoleNameThrowsPdoException(): void
    {
        $this->insertRole('Unique Name');
        $this->expectException(\PDOException::class);
        $this->insertRole('Unique Name');
    }

    public function testDuplicateCheckQueryFindsExistingName(): void
    {
        $this->insertRole('Existing Role');

        $existing = $this->db->fetchOne('SELECT id FROM roles WHERE name = :name', ['name' => 'Existing Role']);
        $this->assertNotNull($existing);
    }

    public function testDuplicateCheckQueryMissesNonExistentName(): void
    {
        $existing = $this->db->fetchOne('SELECT id FROM roles WHERE name = :name', ['name' => 'Ghost']);
        $this->assertNull($existing);
    }

    // ── Role update ────────────────────────────────────────────────────

    public function testUpdateRoleNameAndDescription(): void
    {
        $id = $this->insertRole('Old Name', [], false, false, false, false, 'Old desc');

        $this->db->update('roles', [
            'name' => 'New Name',
            'description' => 'New desc',
            'permissions' => json_encode([]),
            'can_publish_events' => 0,
            'can_access_medical' => 0,
            'can_access_financial' => 0,
        ], ['id' => $id]);

        $row = $this->db->fetchOne('SELECT name, description FROM roles WHERE id = :id', ['id' => $id]);
        $this->assertSame('New Name', $row['name']);
        $this->assertSame('New desc', $row['description']);
    }

    public function testUpdateDoesNotAlterIsSystemFlag(): void
    {
        // The controller never writes is_system during updates — verify it is preserved
        $id = $this->insertRole('Super Admin', [], true);

        $this->db->update('roles', [
            'name' => 'Super Admin',
            'description' => 'Updated',
            'permissions' => json_encode([]),
            'can_publish_events' => 0,
            'can_access_medical' => 0,
            'can_access_financial' => 0,
        ], ['id' => $id]);

        $row = $this->db->fetchOne('SELECT is_system FROM roles WHERE id = :id', ['id' => $id]);
        $this->assertSame(1, (int) $row['is_system']);
    }

    public function testUpdateRolePermissions(): void
    {
        $id = $this->insertRole('Viewer', ['members.read' => true]);
        $newPerms = ['members.read' => true, 'events.read' => true];

        $this->db->update('roles', [
            'name' => 'Viewer',
            'description' => null,
            'permissions' => json_encode($newPerms),
            'can_publish_events' => 0,
            'can_access_medical' => 0,
            'can_access_financial' => 0,
        ], ['id' => $id]);

        $decoded = json_decode(
            $this->db->fetchOne('SELECT permissions FROM roles WHERE id = :id', ['id' => $id])['permissions'],
            true
        );
        $this->assertArrayHasKey('events.read', $decoded);
    }

    // ── System role protection ─────────────────────────────────────────

    public function testSystemRoleFlagIsStored(): void
    {
        $id = $this->insertRole('Group Leader', [], true);
        $row = $this->db->fetchOne('SELECT is_system FROM roles WHERE id = :id', ['id' => $id]);
        $this->assertSame(1, (int) $row['is_system']);
    }

    public function testSystemRoleCanBeDetectedBeforeDelete(): void
    {
        $id = $this->insertRole('Section Leader', [], true);
        $role = $this->db->fetchOne('SELECT * FROM roles WHERE id = :id', ['id' => $id]);

        $this->assertNotNull($role);
        $this->assertSame(1, (int) $role['is_system']);
    }

    // ── Delete validation ──────────────────────────────────────────────

    public function testDeleteNonSystemRoleWithNoAssignments(): void
    {
        $id = $this->insertRole('Temp Role');
        $deleted = $this->db->delete('roles', ['id' => $id]);

        $this->assertSame(1, $deleted);
        $this->assertNull($this->db->fetchOne('SELECT id FROM roles WHERE id = :id', ['id' => $id]));
    }

    public function testActiveAssignmentCountQueryReturnsCorrectValue(): void
    {
        $userId = $this->insertUser();
        $roleId = $this->insertRole('Active Role');
        $this->insertAssignment($userId, $roleId); // active (null end_date)

        $count = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM role_assignments
             WHERE role_id = :id AND (end_date IS NULL OR end_date >= CURDATE())",
            ['id' => $roleId]
        );

        $this->assertSame(1, $count);
    }

    public function testExpiredAssignmentDoesNotBlockDelete(): void
    {
        $userId = $this->insertUser();
        $roleId = $this->insertRole('Past Role');
        $this->insertAssignment($userId, $roleId, '2020-01-01'); // expired

        $count = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM role_assignments
             WHERE role_id = :id AND (end_date IS NULL OR end_date >= CURDATE())",
            ['id' => $roleId]
        );

        $this->assertSame(0, $count);
    }

    public function testRoleWithActiveAssignmentShouldBeBlockedFromDelete(): void
    {
        $userId = $this->insertUser();
        $roleId = $this->insertRole('In Use Role');
        $this->insertAssignment($userId, $roleId); // active

        $count = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM role_assignments
             WHERE role_id = :id AND (end_date IS NULL OR end_date >= CURDATE())",
            ['id' => $roleId]
        );

        $this->assertGreaterThan(0, $count);
    }

    public function testDeleteRoleCascadesToAssignmentsAndScopes(): void
    {
        $userId = $this->insertUser();
        $roleId = $this->insertRole('Cascade Role');
        $assignmentId = $this->insertAssignment($userId, $roleId, '2020-01-01'); // expired, safe to delete

        $this->db->insert('role_assignment_scopes', ['assignment_id' => $assignmentId, 'node_id' => 99]);

        $this->db->delete('roles', ['id' => $roleId]);

        $this->assertNull($this->db->fetchOne(
            'SELECT id FROM role_assignments WHERE id = :id',
            ['id' => $assignmentId]
        ));
        $this->assertNull($this->db->fetchOne(
            'SELECT id FROM role_assignment_scopes WHERE assignment_id = :id',
            ['id' => $assignmentId]
        ));
    }

    // ── Role listing with active assignment count ──────────────────────

    public function testListRolesQueryReturnsActiveAssignmentCount(): void
    {
        $userId1 = $this->insertUser();
        $userId2 = $this->insertUser();
        $roleId = $this->insertRole('Popular Role');

        $this->insertAssignment($userId1, $roleId);          // active
        $this->insertAssignment($userId2, $roleId);          // active
        $this->insertAssignment($userId1, $roleId, '2020-01-01'); // expired — must not count

        $roles = $this->db->fetchAll(
            "SELECT r.*, (SELECT COUNT(*) FROM role_assignments ra
                          WHERE ra.role_id = r.id
                            AND (ra.end_date IS NULL OR ra.end_date >= CURDATE())
             ) AS active_assignments
             FROM roles r ORDER BY r.is_system DESC, r.name ASC"
        );

        $this->assertCount(1, $roles);
        $this->assertSame(2, (int) $roles[0]['active_assignments']);
    }

    public function testListRolesOrdersSystemRolesFirst(): void
    {
        $this->insertRole('Zzz Custom', [], false);
        $this->insertRole('Aaa System', [], true);

        $roles = $this->db->fetchAll(
            "SELECT name FROM roles ORDER BY is_system DESC, name ASC"
        );

        $this->assertSame('Aaa System', $roles[0]['name']);
        $this->assertSame('Zzz Custom', $roles[1]['name']);
    }

    public function testFetchOneReturnsNullForMissingRoleId(): void
    {
        $this->assertNull($this->db->fetchOne('SELECT * FROM roles WHERE id = :id', ['id' => 99999]));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Modules\Directory;

use App\Core\Database;
use App\Modules\Directory\Services\DirectoryService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DirectoryService.
 *
 * Coverage focus:
 *  - getDirectoryMembers(): scope filtering, text search, node filter,
 *    result ordering, empty scope, combined filters
 *  - getContactDirectory(): text search and node_id filter
 *    (scope-only tests already covered by DirectoryScopingTest)
 *
 * Each test gets a freshly created schema so there is no state leakage
 * between runs. The tearDown drops every table created by setUp.
 */
class DirectoryServiceTest extends TestCase
{
    private Database $db;

    // Node IDs
    private int $nodeAlpha;
    private int $nodeBeta;

    // Member IDs
    private int $memberAlice;
    private int $memberBob;
    private int $memberCarol;

    // Role ID
    private int $roleLeader;

    protected function setUp(): void
    {
        try {
            $this->db = new Database(TEST_CONFIG['db']);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
        foreach (['role_assignments', 'roles', 'member_nodes', 'members', 'users', 'org_nodes'] as $t) {
            $this->db->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");

        // ------------------------------------------------------------------
        // Schema
        // ------------------------------------------------------------------
        $this->db->query("CREATE TABLE `org_nodes` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(200) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `users` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `email` VARCHAR(255) NOT NULL UNIQUE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `members` (
            `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `first_name` VARCHAR(100) NOT NULL,
            `surname`    VARCHAR(100) NOT NULL,
            `email`      VARCHAR(255) NULL,
            `phone`      VARCHAR(50)  NULL,
            `photo_path` VARCHAR(500) NULL,
            `status`     VARCHAR(20)  NOT NULL DEFAULT 'active',
            `user_id`    INT UNSIGNED NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `member_nodes` (
            `id`        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `member_id` INT UNSIGNED NOT NULL,
            `node_id`   INT UNSIGNED NOT NULL,
            `is_primary` TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `roles` (
            `id`                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `name`                 VARCHAR(100) NOT NULL,
            `is_directory_visible` TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `role_assignments` (
            `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id`      INT UNSIGNED NOT NULL,
            `role_id`      INT UNSIGNED NOT NULL,
            `context_type` ENUM('node','team') NOT NULL DEFAULT 'node',
            `context_id`   INT UNSIGNED NULL,
            `start_date`   DATE NOT NULL,
            `end_date`     DATE NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // ------------------------------------------------------------------
        // Seed: 2 nodes, 3 active members, 1 inactive
        // ------------------------------------------------------------------
        $this->nodeAlpha = $this->db->insert('org_nodes', ['name' => 'Alpha Troop']);
        $this->nodeBeta  = $this->db->insert('org_nodes', ['name' => 'Beta Pack']);

        $this->roleLeader = $this->db->insert('roles', ['name' => 'Leader', 'is_directory_visible' => 1]);
        $hiddenRole       = $this->db->insert('roles', ['name' => 'Helper', 'is_directory_visible' => 0]);

        $uAlice = $this->db->insert('users', ['email' => 'alice@example.com']);
        $uBob   = $this->db->insert('users', ['email' => 'bob@example.com']);
        $uCarol = $this->db->insert('users', ['email' => 'carol@example.com']);
        $uDave  = $this->db->insert('users', ['email' => 'dave@example.com']);

        $this->memberAlice = $this->db->insert('members', [
            'first_name' => 'Alice', 'surname' => 'Anderson',
            'email'      => 'alice@example.com', 'phone' => '111',
            'status'     => 'active', 'user_id' => $uAlice,
        ]);
        $this->memberBob = $this->db->insert('members', [
            'first_name' => 'Bob', 'surname' => 'Baker',
            'email'      => 'bob@example.com', 'phone' => '222',
            'status'     => 'active', 'user_id' => $uBob,
        ]);
        $this->memberCarol = $this->db->insert('members', [
            'first_name' => 'Carol', 'surname' => 'Clarke',
            'email'      => 'carol@example.com', 'phone' => '333',
            'status'     => 'active', 'user_id' => $uCarol,
        ]);
        // Inactive — must never appear in directory results
        $this->db->insert('members', [
            'first_name' => 'Dave', 'surname' => 'Dixon',
            'email'      => 'dave@example.com', 'phone' => '444',
            'status'     => 'inactive', 'user_id' => $uDave,
        ]);

        // Alice and Bob → Alpha; Carol → Beta; Dave → Beta (inactive)
        $this->db->insert('member_nodes', ['member_id' => $this->memberAlice, 'node_id' => $this->nodeAlpha, 'is_primary' => 1]);
        $this->db->insert('member_nodes', ['member_id' => $this->memberBob,   'node_id' => $this->nodeAlpha, 'is_primary' => 1]);
        $this->db->insert('member_nodes', ['member_id' => $this->memberCarol, 'node_id' => $this->nodeBeta,  'is_primary' => 1]);

        $daveId = $this->db->fetchColumn("SELECT id FROM members WHERE first_name='Dave' LIMIT 1");
        $this->db->insert('member_nodes', ['member_id' => (int) $daveId, 'node_id' => $this->nodeBeta, 'is_primary' => 1]);

        // Role assignments: Alice and Bob as Leader in Alpha; Carol as Leader in Beta
        $this->db->insert('role_assignments', [
            'user_id' => $uAlice, 'role_id' => $this->roleLeader,
            'context_type' => 'node', 'context_id' => $this->nodeAlpha, 'start_date' => '2020-01-01',
        ]);
        $this->db->insert('role_assignments', [
            'user_id' => $uBob, 'role_id' => $this->roleLeader,
            'context_type' => 'node', 'context_id' => $this->nodeAlpha, 'start_date' => '2020-01-01',
        ]);
        $this->db->insert('role_assignments', [
            'user_id' => $uCarol, 'role_id' => $this->roleLeader,
            'context_type' => 'node', 'context_id' => $this->nodeBeta, 'start_date' => '2020-01-01',
        ]);
        // Hidden role assignment for Carol (should not affect contact-directory visibility)
        $this->db->insert('role_assignments', [
            'user_id' => $uCarol, 'role_id' => $hiddenRole,
            'context_type' => 'node', 'context_id' => $this->nodeBeta, 'start_date' => '2020-01-01',
        ]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
        foreach (['role_assignments', 'roles', 'member_nodes', 'members', 'users', 'org_nodes'] as $t) {
            $this->db->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");
    }

    // -----------------------------------------------------------------------
    // getDirectoryMembers() — scope filtering
    // -----------------------------------------------------------------------

    public function testGetDirectoryMembersReturnsAllActiveInScope(): void
    {
        $svc = new DirectoryService($this->db);
        $rows = $svc->getDirectoryMembers([$this->nodeAlpha, $this->nodeBeta]);

        $names = array_column($rows, 'member_name');
        sort($names);

        $this->assertSame(['Alice Anderson', 'Bob Baker', 'Carol Clarke'], $names);
    }

    public function testGetDirectoryMembersExcludesInactiveMembers(): void
    {
        $svc = new DirectoryService($this->db);
        $rows = $svc->getDirectoryMembers([$this->nodeAlpha, $this->nodeBeta]);

        $names = array_column($rows, 'member_name');
        $this->assertNotContains('Dave Dixon', $names);
    }

    public function testGetDirectoryMembersScopedToSingleNode(): void
    {
        $svc = new DirectoryService($this->db);
        $rows = $svc->getDirectoryMembers([$this->nodeAlpha]);

        $names = array_column($rows, 'member_name');
        sort($names);

        $this->assertSame(['Alice Anderson', 'Bob Baker'], $names);
        $this->assertNotContains('Carol Clarke', $names);
    }

    public function testGetDirectoryMembersEmptyScopeReturnsAll(): void
    {
        // When scopeNodeIds is empty the WHERE clause has no scope restriction,
        // so all active members are returned.
        $svc = new DirectoryService($this->db);
        $rows = $svc->getDirectoryMembers([]);

        $names = array_column($rows, 'member_name');
        // At least the 3 active members must appear
        $this->assertContains('Alice Anderson', $names);
        $this->assertContains('Bob Baker', $names);
        $this->assertContains('Carol Clarke', $names);
    }

    // -----------------------------------------------------------------------
    // getDirectoryMembers() — ordering
    // -----------------------------------------------------------------------

    public function testGetDirectoryMembersOrderedBySurnameAscThenFirstName(): void
    {
        $svc = new DirectoryService($this->db);
        $rows = $svc->getDirectoryMembers([$this->nodeAlpha, $this->nodeBeta]);

        $names = array_column($rows, 'member_name');
        $this->assertSame(['Alice Anderson', 'Bob Baker', 'Carol Clarke'], $names);
    }

    // -----------------------------------------------------------------------
    // getDirectoryMembers() — free-text search
    // -----------------------------------------------------------------------

    public function testGetDirectoryMembersSearchByFirstName(): void
    {
        $svc = new DirectoryService($this->db);
        $rows = $svc->getDirectoryMembers([$this->nodeAlpha, $this->nodeBeta], 'Alice');

        $this->assertCount(1, $rows);
        $this->assertSame('Alice Anderson', $rows[0]['member_name']);
    }

    public function testGetDirectoryMembersSearchBySurname(): void
    {
        $svc = new DirectoryService($this->db);
        $rows = $svc->getDirectoryMembers([$this->nodeAlpha, $this->nodeBeta], 'Baker');

        $this->assertCount(1, $rows);
        $this->assertSame('Bob Baker', $rows[0]['member_name']);
    }

    public function testGetDirectoryMembersSearchByEmail(): void
    {
        $svc = new DirectoryService($this->db);
        $rows = $svc->getDirectoryMembers([$this->nodeAlpha, $this->nodeBeta], 'carol@example');

        $this->assertCount(1, $rows);
        $this->assertSame('Carol Clarke', $rows[0]['member_name']);
    }

    public function testGetDirectoryMembersSearchByFullName(): void
    {
        $svc = new DirectoryService($this->db);
        $rows = $svc->getDirectoryMembers([$this->nodeAlpha, $this->nodeBeta], 'Bob Baker');

        $this->assertCount(1, $rows);
        $this->assertSame('Bob Baker', $rows[0]['member_name']);
    }

    public function testGetDirectoryMembersSearchIsCaseInsensitive(): void
    {
        $svc = new DirectoryService($this->db);
        $rows = $svc->getDirectoryMembers([$this->nodeAlpha, $this->nodeBeta], 'anderson');

        $this->assertCount(1, $rows);
        $this->assertSame('Alice Anderson', $rows[0]['member_name']);
    }

    public function testGetDirectoryMembersSearchWithNoMatchReturnsEmpty(): void
    {
        $svc = new DirectoryService($this->db);
        $rows = $svc->getDirectoryMembers([$this->nodeAlpha, $this->nodeBeta], 'Zaphod Beeblebrox');

        $this->assertCount(0, $rows);
    }

    public function testGetDirectoryMembersEmptySearchReturnsAll(): void
    {
        $svc = new DirectoryService($this->db);
        $rows = $svc->getDirectoryMembers([$this->nodeAlpha, $this->nodeBeta], '');

        $this->assertCount(3, $rows);
    }

    public function testGetDirectoryMembersWhitespaceSearchReturnsAll(): void
    {
        $svc = new DirectoryService($this->db);
        $rows = $svc->getDirectoryMembers([$this->nodeAlpha, $this->nodeBeta], '   ');

        $this->assertCount(3, $rows);
    }

    // -----------------------------------------------------------------------
    // getDirectoryMembers() — node_id filter
    // -----------------------------------------------------------------------

    public function testGetDirectoryMembersFilterByNodeId(): void
    {
        $svc = new DirectoryService($this->db);
        $rows = $svc->getDirectoryMembers(
            [$this->nodeAlpha, $this->nodeBeta],
            null,
            $this->nodeBeta
        );

        $names = array_column($rows, 'member_name');
        $this->assertSame(['Carol Clarke'], $names);
    }

    public function testGetDirectoryMembersNodeFilterAndSearchCombined(): void
    {
        $svc = new DirectoryService($this->db);
        // Restrict to Alpha AND search for 'Alice'
        $rows = $svc->getDirectoryMembers(
            [$this->nodeAlpha],
            'Alice',
            $this->nodeAlpha
        );

        $this->assertCount(1, $rows);
        $this->assertSame('Alice Anderson', $rows[0]['member_name']);
    }

    public function testGetDirectoryMembersNodeFilterAndSearchNoMatch(): void
    {
        $svc = new DirectoryService($this->db);
        // Alpha only, but searching for Carol who is in Beta
        $rows = $svc->getDirectoryMembers(
            [$this->nodeAlpha],
            'Carol',
            $this->nodeAlpha
        );

        $this->assertCount(0, $rows);
    }

    // -----------------------------------------------------------------------
    // getDirectoryMembers() — result shape
    // -----------------------------------------------------------------------

    public function testGetDirectoryMembersRowContainsExpectedFields(): void
    {
        $svc = new DirectoryService($this->db);
        $rows = $svc->getDirectoryMembers([$this->nodeAlpha]);

        $this->assertNotEmpty($rows);
        $row = $rows[0];

        foreach (['id', 'member_name', 'first_name', 'surname', 'email', 'phone', 'photo_path', 'primary_node_name'] as $field) {
            $this->assertArrayHasKey($field, $row, "Row is missing field '{$field}'");
        }
    }

    public function testGetDirectoryMembersPrimaryNodeNameIsPopulated(): void
    {
        $svc = new DirectoryService($this->db);
        $rows = $svc->getDirectoryMembers([$this->nodeAlpha]);

        // Both Alpha members should have 'Alpha Troop' as primary node
        foreach ($rows as $row) {
            $this->assertSame('Alpha Troop', $row['primary_node_name']);
        }
    }

    // -----------------------------------------------------------------------
    // getContactDirectory() — search (not covered by DirectoryScopingTest)
    // -----------------------------------------------------------------------

    public function testGetContactDirectorySearchByMemberName(): void
    {
        $svc = new DirectoryService($this->db);
        $contacts = $svc->getContactDirectory(null, 'Alice');

        $this->assertCount(1, $contacts);
        $this->assertSame('Alice Anderson', $contacts[0]['member_name']);
    }

    public function testGetContactDirectorySearchByRoleName(): void
    {
        $svc = new DirectoryService($this->db);
        $contacts = $svc->getContactDirectory(null, 'Leader');

        // All 3 active members have the Leader role
        $this->assertCount(3, $contacts);
    }

    public function testGetContactDirectorySearchByNodeName(): void
    {
        $svc = new DirectoryService($this->db);
        $contacts = $svc->getContactDirectory(null, 'Beta');

        $names = array_column($contacts, 'member_name');
        $this->assertSame(['Carol Clarke'], $names);
    }

    public function testGetContactDirectorySearchNoMatchReturnsEmpty(): void
    {
        $svc = new DirectoryService($this->db);
        $contacts = $svc->getContactDirectory(null, 'Nonexistent Person');

        $this->assertCount(0, $contacts);
    }

    // -----------------------------------------------------------------------
    // getContactDirectory() — node_id filter (specific node, not scope)
    // -----------------------------------------------------------------------

    public function testGetContactDirectoryFilteredByNodeId(): void
    {
        $svc = new DirectoryService($this->db);
        $contacts = $svc->getContactDirectory($this->nodeAlpha);

        $names = array_column($contacts, 'member_name');
        sort($names);

        $this->assertSame(['Alice Anderson', 'Bob Baker'], $names);
    }

    public function testGetContactDirectoryNodeIdFilterAndSearchCombined(): void
    {
        $svc = new DirectoryService($this->db);
        $contacts = $svc->getContactDirectory($this->nodeAlpha, 'Bob');

        $this->assertCount(1, $contacts);
        $this->assertSame('Bob Baker', $contacts[0]['member_name']);
    }

    // -----------------------------------------------------------------------
    // getContactDirectory() — hidden roles excluded
    // -----------------------------------------------------------------------

    public function testGetContactDirectoryExcludesNonDirectoryVisibleRoles(): void
    {
        $svc = new DirectoryService($this->db);
        // Carol has both a Leader (visible) and Helper (hidden) role.
        // getContactDirectory should return Carol once (via the visible role only).
        $contacts = $svc->getContactDirectory(null, 'Carol');

        $roleNames = array_column($contacts, 'role_name');
        $this->assertNotContains('Helper', $roleNames);
        $this->assertContains('Leader', $roleNames);
    }

    // -----------------------------------------------------------------------
    // getContactDirectory() — result shape
    // -----------------------------------------------------------------------

    public function testGetContactDirectoryRowContainsExpectedFields(): void
    {
        $svc = new DirectoryService($this->db);
        $contacts = $svc->getContactDirectory();

        $this->assertNotEmpty($contacts);
        $row = $contacts[0];

        foreach (['member_name', 'role_name', 'email', 'phone', 'node_name'] as $field) {
            $this->assertArrayHasKey($field, $row, "Row is missing field '{$field}'");
        }
    }
}

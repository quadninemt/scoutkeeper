<?php

declare(strict_types=1);

namespace Tests\Modules\Members;

use PHPUnit\Framework\TestCase;
use App\Core\Database;
use App\Modules\Members\Services\RegistrationService;

/**
 * Tests for RegistrationService.
 *
 * Covers self-registration, approval, rejection, pending listings,
 * invitation creation, validation, and processing.
 */
class MemberRegTest extends TestCase
{
    private Database $db;
    private RegistrationService $service;

    protected function setUp(): void
    {
        try {
            $this->db = new Database(TEST_CONFIG['db']);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        // Drop in dependency order
        $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
        $this->db->query("DROP TABLE IF EXISTS `registration_invitations`");
        $this->db->query("DROP TABLE IF EXISTS `waiting_list`");
        $this->db->query("DROP TABLE IF EXISTS `member_attachments`");
        $this->db->query("DROP TABLE IF EXISTS `member_timeline`");
        $this->db->query("DROP TABLE IF EXISTS `medical_access_log`");
        $this->db->query("DROP TABLE IF EXISTS `member_pending_changes`");
        $this->db->query("DROP TABLE IF EXISTS `member_nodes`");
        $this->db->query("DROP TABLE IF EXISTS `members`");
        $this->db->query("DROP TABLE IF EXISTS `custom_field_definitions`");
        $this->db->query("DROP TABLE IF EXISTS `org_closure`");
        $this->db->query("DROP TABLE IF EXISTS `org_teams`");
        $this->db->query("DROP TABLE IF EXISTS `org_nodes`");
        $this->db->query("DROP TABLE IF EXISTS `org_level_types`");
        $this->db->query("DROP TABLE IF EXISTS `role_assignment_scopes`");
        $this->db->query("DROP TABLE IF EXISTS `role_assignments`");
        $this->db->query("DROP TABLE IF EXISTS `roles`");
        $this->db->query("DROP TABLE IF EXISTS `password_resets`");
        $this->db->query("DROP TABLE IF EXISTS `user_sessions`");
        $this->db->query("DROP TABLE IF EXISTS `users`");
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");

        // Create users table
        $this->db->query("
            CREATE TABLE `users` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `email` VARCHAR(255) NOT NULL UNIQUE,
                `password_hash` VARCHAR(255) NOT NULL DEFAULT '',
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `is_super_admin` TINYINT(1) NOT NULL DEFAULT 0,
                `mfa_secret` TEXT NULL,
                `password_changed_at` DATETIME NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create org_level_types
        $this->db->query("
            CREATE TABLE `org_level_types` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL,
                `depth` INT UNSIGNED NOT NULL DEFAULT 0,
                `is_leaf` TINYINT(1) NOT NULL DEFAULT 0,
                `sort_order` INT UNSIGNED NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create org_nodes
        $this->db->query("
            CREATE TABLE `org_nodes` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(200) NOT NULL,
                `short_name` VARCHAR(50) NULL,
                `parent_id` INT UNSIGNED NULL,
                `level_type_id` INT UNSIGNED NULL,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT `fk_node_parent` FOREIGN KEY (`parent_id`) REFERENCES `org_nodes` (`id`) ON DELETE SET NULL,
                CONSTRAINT `fk_node_level` FOREIGN KEY (`level_type_id`) REFERENCES `org_level_types` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create members table
        $this->db->query("
            CREATE TABLE `members` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `membership_number` VARCHAR(20) NOT NULL,
                `first_name` VARCHAR(100) NOT NULL,
                `surname` VARCHAR(100) NOT NULL,
                `email` VARCHAR(255) NULL,
                `phone` VARCHAR(50) NULL,
                `dob` DATE NULL,
                `gender` ENUM('male','female','other','prefer_not_to_say') NULL,
                `address_line1` VARCHAR(200) NULL,
                `address_line2` VARCHAR(200) NULL,
                `city` VARCHAR(100) NULL,
                `postcode` VARCHAR(20) NULL,
                `country` VARCHAR(100) NULL,
                `status` ENUM('active','pending','suspended','inactive','left') NOT NULL DEFAULT 'pending',
                `status_reason` TEXT NULL,
                `joined_date` DATE NULL,
                `left_date` DATE NULL,
                `user_id` INT UNSIGNED NULL,
                `gdpr_consent` TINYINT(1) NOT NULL DEFAULT 0,
                `member_custom_data` JSON NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FULLTEXT KEY `ft_member_search` (`first_name`, `surname`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create member_nodes
        $this->db->query("
            CREATE TABLE `member_nodes` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `member_id` INT UNSIGNED NOT NULL,
                `node_id` INT UNSIGNED NOT NULL,
                `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
                CONSTRAINT `fk_mn_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_mn_node` FOREIGN KEY (`node_id`) REFERENCES `org_nodes` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Create registration_invitations
        $this->db->query("
            CREATE TABLE `registration_invitations` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `token` VARCHAR(64) NOT NULL,
                `target_node_id` INT UNSIGNED NOT NULL,
                `created_by` INT UNSIGNED NULL,
                `email` VARCHAR(255) NULL,
                `expires_at` DATETIME NOT NULL,
                `used_at` DATETIME NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_invitation_token` (`token`),
                CONSTRAINT `fk_inv_node` FOREIGN KEY (`target_node_id`) REFERENCES `org_nodes` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_inv_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Seed a node
        $this->db->insert('org_level_types', ['name' => 'Group', 'depth' => 0, 'is_leaf' => 1, 'sort_order' => 0]);
        $this->db->insert('org_nodes', ['name' => 'Test Group', 'level_type_id' => 1]);

        // Seed an admin user
        $this->db->insert('users', [
            'email' => 'admin@test.local',
            'password_hash' => password_hash('testpass', PASSWORD_BCRYPT),
            'is_super_admin' => 1,
        ]);

        $this->service = new RegistrationService($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
        $this->db->query("DROP TABLE IF EXISTS `registration_invitations`");
        $this->db->query("DROP TABLE IF EXISTS `member_nodes`");
        $this->db->query("DROP TABLE IF EXISTS `members`");
        $this->db->query("DROP TABLE IF EXISTS `org_nodes`");
        $this->db->query("DROP TABLE IF EXISTS `org_level_types`");
        $this->db->query("DROP TABLE IF EXISTS `users`");
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");
    }

    // ── Self Registration ────────────────────────────────────────────

    public function testSelfRegisterCreatesAMemberWithPendingStatus(): void
    {
        $result = $this->service->selfRegister([
            'first_name' => 'Jane',
            'surname' => 'Doe',
            'email' => 'jane@test.local',
        ]);

        $this->assertArrayHasKey('member_id', $result);
        $this->assertNull($result['user_id']);

        $member = $this->db->fetchOne("SELECT * FROM `members` WHERE `id` = ?", [$result['member_id']]);
        $this->assertSame('pending', $member['status']);
        $this->assertSame('jane@test.local', $member['email']);
        $this->assertNotEmpty($member['membership_number']);
    }

    public function testSelfRegisterWithPasswordCreatesUserAccount(): void
    {
        $result = $this->service->selfRegister([
            'first_name' => 'John',
            'surname' => 'Smith',
            'email' => 'john@test.local',
        ], null, 'securepassword');

        $this->assertNotNull($result['user_id']);

        $user = $this->db->fetchOne("SELECT * FROM `users` WHERE `id` = ?", [$result['user_id']]);
        $this->assertSame('john@test.local', $user['email']);
        $this->assertEquals(0, $user['is_active']); // Inactive until approved
    }

    public function testSelfRegisterAssignsToNode(): void
    {
        $result = $this->service->selfRegister([
            'first_name' => 'Anna',
            'surname' => 'Brown',
            'email' => 'anna@test.local',
        ], 1);

        $node = $this->db->fetchOne(
            "SELECT * FROM `member_nodes` WHERE `member_id` = ?",
            [$result['member_id']]
        );
        $this->assertNotNull($node);
        $this->assertEquals(1, $node['node_id']);
        $this->assertEquals(1, $node['is_primary']);
    }

    public function testSelfRegisterRequiresFirstAndSurname(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->selfRegister(['email' => 'test@test.local']);
    }

    public function testSelfRegisterRequiresEmail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->selfRegister([
            'first_name' => 'Test',
            'surname' => 'User',
        ]);
    }

    public function testSelfRegisterRejectsDuplicateEmail(): void
    {
        $this->service->selfRegister([
            'first_name' => 'First',
            'surname' => 'User',
            'email' => 'dup@test.local',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already exists');
        $this->service->selfRegister([
            'first_name' => 'Second',
            'surname' => 'User',
            'email' => 'dup@test.local',
        ]);
    }

    // ── Approval / Rejection ─────────────────────────────────────────

    public function testApproveRegistrationSetsMemberActive(): void
    {
        $result = $this->service->selfRegister([
            'first_name' => 'Pending',
            'surname' => 'Member',
            'email' => 'pending@test.local',
        ]);

        $this->service->approveRegistration($result['member_id'], 1);

        $member = $this->db->fetchOne("SELECT * FROM `members` WHERE `id` = ?", [$result['member_id']]);
        $this->assertSame('active', $member['status']);
        $this->assertNotNull($member['joined_date']);
    }

    public function testApproveActivatesLinkedUserAccount(): void
    {
        $result = $this->service->selfRegister([
            'first_name' => 'User',
            'surname' => 'Account',
            'email' => 'useracct@test.local',
        ], null, 'password12');

        $this->service->approveRegistration($result['member_id'], 1);

        $user = $this->db->fetchOne("SELECT * FROM `users` WHERE `id` = ?", [$result['user_id']]);
        $this->assertEquals(1, $user['is_active']);
    }

    public function testApproveNonPendingThrows(): void
    {
        $result = $this->service->selfRegister([
            'first_name' => 'Active',
            'surname' => 'Already',
            'email' => 'active@test.local',
        ]);
        $this->service->approveRegistration($result['member_id'], 1);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->approveRegistration($result['member_id'], 1);
    }

    public function testRejectRegistrationSetsInactive(): void
    {
        $result = $this->service->selfRegister([
            'first_name' => 'Reject',
            'surname' => 'Me',
            'email' => 'reject@test.local',
        ]);

        $this->service->rejectRegistration($result['member_id'], 1, 'Too young');

        $member = $this->db->fetchOne("SELECT * FROM `members` WHERE `id` = ?", [$result['member_id']]);
        $this->assertSame('inactive', $member['status']);
        $this->assertSame('Too young', $member['status_reason']);
    }

    public function testRejectDeactivatesLinkedUser(): void
    {
        $result = $this->service->selfRegister([
            'first_name' => 'Reject',
            'surname' => 'WithUser',
            'email' => 'rejectuser@test.local',
        ], null, 'password12');

        $this->service->rejectRegistration($result['member_id'], 1);

        $user = $this->db->fetchOne("SELECT * FROM `users` WHERE `id` = ?", [$result['user_id']]);
        $this->assertEquals(0, $user['is_active']);
    }

    // ── Pending Listings ─────────────────────────────────────────────

    public function testGetPendingRegistrations(): void
    {
        $this->service->selfRegister([
            'first_name' => 'P1', 'surname' => 'User', 'email' => 'p1@test.local',
        ]);
        $this->service->selfRegister([
            'first_name' => 'P2', 'surname' => 'User', 'email' => 'p2@test.local',
        ]);

        $pending = $this->service->getPendingRegistrations();
        $this->assertCount(2, $pending);
    }

    public function testGetPendingRegistrationsScopedByNode(): void
    {
        $this->service->selfRegister([
            'first_name' => 'Scoped', 'surname' => 'User', 'email' => 'scoped@test.local',
        ], 1);
        $this->service->selfRegister([
            'first_name' => 'NoNode', 'surname' => 'User', 'email' => 'nonode@test.local',
        ]);

        $pending = $this->service->getPendingRegistrations([1]);
        $this->assertCount(1, $pending);
        $this->assertSame('Scoped', $pending[0]['first_name']);
    }

    // ── Invitations ──────────────────────────────────────────────────

    public function testCreateInvitationReturnsToken(): void
    {
        $token = $this->service->createInvitation(1, 1);
        $this->assertSame(64, strlen($token));
    }

    public function testCreateInvitationWithTargetEmail(): void
    {
        $token = $this->service->createInvitation(1, 1, 'target@test.local');

        $inv = $this->db->fetchOne("SELECT * FROM `registration_invitations` WHERE `token` = ?", [$token]);
        $this->assertSame('target@test.local', $inv['email']);
    }

    public function testGetValidInvitation(): void
    {
        $token = $this->service->createInvitation(1, 1);
        $inv = $this->service->getValidInvitation($token);

        $this->assertNotNull($inv);
        $this->assertSame($token, $inv['token']);
    }

    public function testGetValidInvitationReturnsNullForBadToken(): void
    {
        $inv = $this->service->getValidInvitation('nonexistent_token');
        $this->assertNull($inv);
    }

    public function testProcessInvitationCreatesMember(): void
    {
        $token = $this->service->createInvitation(1, 1);

        $result = $this->service->processInvitation($token, [
            'first_name' => 'Invited',
            'surname' => 'Member',
            'email' => 'invited@test.local',
        ]);

        $this->assertArrayHasKey('member_id', $result);

        // Invitation should be marked as used
        $inv = $this->db->fetchOne("SELECT * FROM `registration_invitations` WHERE `token` = ?", [$token]);
        $this->assertNotNull($inv['used_at']);
    }

    public function testProcessInvitationRejectsEmailMismatch(): void
    {
        $token = $this->service->createInvitation(1, 1, 'specific@test.local');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not match');
        $this->service->processInvitation($token, [
            'first_name' => 'Wrong',
            'surname' => 'Email',
            'email' => 'wrong@test.local',
        ]);
    }

    public function testProcessInvitationRejectsUsedToken(): void
    {
        $token = $this->service->createInvitation(1, 1);
        $this->service->processInvitation($token, [
            'first_name' => 'First', 'surname' => 'Use', 'email' => 'first@test.local',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->processInvitation($token, [
            'first_name' => 'Second', 'surname' => 'Use', 'email' => 'second@test.local',
        ]);
    }

    public function testGetInvitationsForNode(): void
    {
        $this->service->createInvitation(1, 1);
        $this->service->createInvitation(1, 1, 'test@test.local');

        $invitations = $this->service->getInvitations(1);
        $this->assertCount(2, $invitations);
    }

    // ── Membership Number ────────────────────────────────────────────

    public function testMembershipNumbersAreSequential(): void
    {
        $r1 = $this->service->selfRegister([
            'first_name' => 'A', 'surname' => 'One', 'email' => 'a@test.local',
        ]);
        $r2 = $this->service->selfRegister([
            'first_name' => 'B', 'surname' => 'Two', 'email' => 'b@test.local',
        ]);

        $m1 = $this->db->fetchOne("SELECT membership_number FROM `members` WHERE `id` = ?", [$r1['member_id']]);
        $m2 = $this->db->fetchOne("SELECT membership_number FROM `members` WHERE `id` = ?", [$r2['member_id']]);

        $this->assertStringStartsWith('SK-', $m1['membership_number']);
        $this->assertStringStartsWith('SK-', $m2['membership_number']);

        $num1 = (int) substr($m1['membership_number'], 3);
        $num2 = (int) substr($m2['membership_number'], 3);
        $this->assertSame($num1 + 1, $num2);
    }
}

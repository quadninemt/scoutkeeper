<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Core\Database;
use App\Core\HtmlSanitizer;

/**
 * Terms & Conditions versioning service.
 *
 * Manages versioned terms documents with a publish/unpublish workflow,
 * user acceptance tracking, grace period enforcement, and acceptance
 * reporting. Only one version may be published at a time.
 */
class TermsService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // ──── Version management ────

    /**
     * Create a new terms version (draft).
     *
     * @param array $data Must include: title, content, version_number.
     *                     Optional: grace_period_days (default 14).
     * @param int $createdBy User ID of the creator
     * @return int The new version ID
     * @throws \InvalidArgumentException if required fields are missing
     */
    public function createVersion(array $data, int $createdBy): int
    {
        if (empty(trim($data['title'] ?? ''))) {
            throw new \InvalidArgumentException('Title is required');
        }
        if (empty(trim($data['content'] ?? ''))) {
            throw new \InvalidArgumentException('Content is required');
        }
        if (empty(trim($data['version_number'] ?? ''))) {
            throw new \InvalidArgumentException('Version number is required');
        }
        if (empty($data['policy_id'])) {
            throw new \InvalidArgumentException('Policy is required');
        }

        return $this->db->insert('terms_versions', [
            'policy_id' => (int) $data['policy_id'],
            'title' => trim($data['title']),
            'content' => HtmlSanitizer::sanitize($data['content']),
            'version_number' => trim($data['version_number']),
            'grace_period_days' => (int) ($data['grace_period_days'] ?? 14),
            'is_published' => 0,
            'created_by' => $createdBy,
        ]);
    }

    /**
     * Update a terms version (only drafts should be edited in practice).
     *
     * @param int $id Version ID
     * @param array $data Fields to update (title, content, version_number, grace_period_days)
     */
    public function updateVersion(int $id, array $data): void
    {
        $updateData = [];

        if (array_key_exists('title', $data)) {
            if (empty(trim($data['title']))) {
                throw new \InvalidArgumentException('Title cannot be empty');
            }
            $updateData['title'] = trim($data['title']);
        }

        if (array_key_exists('content', $data)) {
            if (empty(trim($data['content']))) {
                throw new \InvalidArgumentException('Content cannot be empty');
            }
            $updateData['content'] = HtmlSanitizer::sanitize($data['content']);
        }

        if (array_key_exists('version_number', $data)) {
            if (empty(trim($data['version_number']))) {
                throw new \InvalidArgumentException('Version number cannot be empty');
            }
            $updateData['version_number'] = trim($data['version_number']);
        }

        if (array_key_exists('grace_period_days', $data)) {
            $updateData['grace_period_days'] = (int) $data['grace_period_days'];
        }

        if (!empty($updateData)) {
            $this->db->update('terms_versions', $updateData, ['id' => $id]);
        }
    }

    /**
     * Publish a terms version.
     *
     * Unpublishes any previously published version first, then marks the
     * given version as published with the current timestamp.
     *
     * @param int $id Version ID to publish
     */
    public function publishVersion(int $id): void
    {
        $version = $this->db->fetchOne(
            "SELECT policy_id FROM `terms_versions` WHERE id = :id",
            ['id' => $id]
        );
        if ($version === null) {
            return;
        }

        // Unpublish any currently published version of the SAME policy only
        $this->db->query(
            "UPDATE `terms_versions` SET `is_published` = 0
             WHERE `is_published` = 1 AND `policy_id` = :pid",
            ['pid' => (int) $version['policy_id']]
        );

        $this->db->update('terms_versions', [
            'is_published' => 1,
            'published_at' => gmdate('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    /**
     * Get all versions for a given policy, newest first.
     */
    public function getVersionsByPolicy(int $policyId): array
    {
        return $this->db->fetchAll(
            "SELECT tv.*, u.email AS created_by_email
             FROM `terms_versions` tv
             LEFT JOIN `users` u ON u.id = tv.created_by
             WHERE tv.policy_id = :pid
             ORDER BY tv.created_at DESC",
            ['pid' => $policyId]
        );
    }

    /**
     * Get all terms versions ordered by creation date (newest first).
     *
     * @return array List of version records
     */
    public function getVersions(): array
    {
        return $this->db->fetchAll(
            "SELECT tv.*, u.email AS created_by_email
             FROM `terms_versions` tv
             LEFT JOIN `users` u ON u.id = tv.created_by
             ORDER BY tv.created_at DESC"
        );
    }

    /**
     * Get the currently published terms version.
     *
     * @return array|null The published version, or null if none is published
     */
    public function getCurrentVersion(): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM `terms_versions` WHERE `is_published` = 1 LIMIT 1"
        );
    }

    /**
     * Get a terms version by its ID.
     *
     * @param int $id Version ID
     * @return array|null The version record, or null if not found
     */
    public function getVersionById(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT tv.*, u.email AS created_by_email
             FROM `terms_versions` tv
             LEFT JOIN `users` u ON u.id = tv.created_by
             WHERE tv.id = :id",
            ['id' => $id]
        );
    }

    // ──── Acceptance ────

    /**
     * Record a user's acceptance of a terms version.
     *
     * Uses INSERT IGNORE to silently skip if the user has already
     * accepted this version (enforced by the unique key).
     *
     * @param int $versionId Terms version ID
     * @param int $userId User ID
     * @param string|null $ip Client IP address for audit purposes
     */
    public function acceptTerms(int $versionId, int $userId, ?string $ip = null): void
    {
        $this->db->query(
            "INSERT IGNORE INTO `terms_acceptances` (`terms_version_id`, `user_id`, `ip_address`)
             VALUES (:version_id, :user_id, :ip)",
            [
                'version_id' => $versionId,
                'user_id' => $userId,
                'ip' => $ip,
            ]
        );
    }

    /**
     * Check whether a user has accepted a specific terms version.
     *
     * If no versionId is given, checks against the currently published version.
     *
     * @param int $userId User ID
     * @param int|null $versionId Version ID (null = current published version)
     * @return bool True if the user has accepted
     */
    public function hasAccepted(int $userId, ?int $versionId = null): bool
    {
        if ($versionId === null) {
            $current = $this->getCurrentVersion();
            if ($current === null) {
                // No published terms — nothing to accept
                return true;
            }
            $versionId = (int) $current['id'];
        }

        $row = $this->db->fetchOne(
            "SELECT 1 FROM `terms_acceptances`
             WHERE `terms_version_id` = :version_id AND `user_id` = :user_id",
            ['version_id' => $versionId, 'user_id' => $userId]
        );

        return $row !== null;
    }

    /**
     * Get the acceptance report for a specific terms version.
     *
     * Returns a list of users who accepted, with their email and
     * acceptance timestamp.
     *
     * @param int $versionId Terms version ID
     * @return array List of acceptance records with user details
     */
    public function getAcceptanceReport(int $versionId): array
    {
        return $this->db->fetchAll(
            "SELECT ta.*, u.email AS user_email
             FROM `terms_acceptances` ta
             JOIN `users` u ON u.id = ta.user_id
             WHERE ta.terms_version_id = :version_id
             ORDER BY ta.accepted_at DESC",
            ['version_id' => $versionId]
        );
    }

    // ──── Grace period ────

    /**
     * Check whether the grace period for the current terms is still active.
     *
     * Returns true if the currently published terms were published within
     * the last N days (where N = grace_period_days). During the grace
     * period, users are not forced to accept immediately.
     *
     * @param int|null $userId Unused — reserved for future per-user overrides
     * @return bool True if within the grace period
     */
    public function isInGracePeriod(?int $userId = null): bool
    {
        $current = $this->getCurrentVersion();

        if ($current === null || $current['published_at'] === null) {
            return false;
        }

        $publishedAt = new \DateTimeImmutable($current['published_at']);
        $graceDays = (int) $current['grace_period_days'];
        $graceEnd = $publishedAt->modify("+{$graceDays} days");

        return new \DateTimeImmutable() < $graceEnd;
    }

    /**
     * Determine whether a user must accept the current terms before proceeding.
     *
     * Returns true when there is a published version the user has not
     * accepted AND the grace period has expired.
     *
     * @param int $userId User ID
     * @return bool True if the user must accept terms
     */
    public function requiresAcceptance(int $userId): bool
    {
        $current = $this->getCurrentVersion();

        if ($current === null) {
            return false;
        }

        // Already accepted the current version
        if ($this->hasAccepted($userId, (int) $current['id'])) {
            return false;
        }

        // Still within the grace period
        if ($this->isInGracePeriod()) {
            return false;
        }

        return true;
    }
}

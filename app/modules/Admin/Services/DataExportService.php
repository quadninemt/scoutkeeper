<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Core\Csv;
use App\Core\Database;

/**
 * Data export service.
 *
 * Generates CSV, XML, and JSON exports for members, individual GDPR
 * subject access requests, and system settings.
 */
class DataExportService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Export members as CSV, optionally scoped by organisation nodes.
     *
     * Columns: membership_number, first_name, surname, email, phone,
     * dob, gender, status, joined_date, node_names.
     *
     * @param array|null $nodeIds Limit to members belonging to these nodes (null = all)
     * @return string CSV content
     */
    public function exportMembersCsv(?array $nodeIds = null): string
    {
        $members = $this->fetchMembers($nodeIds);

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Failed to open temporary stream for CSV export');
        }

        // Header row
        Csv::put($handle, [
            'membership_number', 'first_name', 'surname', 'email', 'phone',
            'dob', 'gender', 'status', 'joined_date', 'node_names',
        ]);

        foreach ($members as $row) {
            Csv::put($handle, [
                $row['membership_number'],
                $row['first_name'],
                $row['surname'],
                $row['email'] ?? '',
                $row['phone'] ?? '',
                $row['dob'] ?? '',
                $row['gender'] ?? '',
                $row['status'],
                $row['joined_date'] ?? '',
                $row['node_names'] ?? '',
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv !== false ? $csv : '';
    }

    /**
     * Export members as XML, optionally scoped by organisation nodes.
     *
     * @param array|null $nodeIds Limit to members belonging to these nodes (null = all)
     * @return string XML content
     */
    public function exportMembersXml(?array $nodeIds = null): string
    {
        $members = $this->fetchMembers($nodeIds);

        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><members/>');

        foreach ($members as $row) {
            $member = $xml->addChild('member');
            $member->addChild('membership_number', $this->xmlEscape($row['membership_number']));
            $member->addChild('first_name', $this->xmlEscape($row['first_name']));
            $member->addChild('surname', $this->xmlEscape($row['surname']));
            $member->addChild('email', $this->xmlEscape($row['email'] ?? ''));
            $member->addChild('phone', $this->xmlEscape($row['phone'] ?? ''));
            $member->addChild('dob', $this->xmlEscape($row['dob'] ?? ''));
            $member->addChild('gender', $this->xmlEscape($row['gender'] ?? ''));
            $member->addChild('status', $this->xmlEscape($row['status']));
            $member->addChild('joined_date', $this->xmlEscape($row['joined_date'] ?? ''));
            $member->addChild('node_names', $this->xmlEscape($row['node_names'] ?? ''));
        }

        $output = $xml->asXML();

        return $output !== false ? $output : '';
    }

    /**
     * Export all data for a single member (GDPR subject access request).
     *
     * Includes: all member fields, custom data, timeline entries,
     * and role assignments.
     *
     * @param int $memberId Member ID
     * @return string CSV content
     * @throws \RuntimeException If the member does not exist
     */
    public function exportMyDataCsv(int $memberId): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Failed to open temporary stream for CSV export');
        }

        // ── Member profile ──
        $member = $this->db->fetchOne(
            "SELECT m.*, GROUP_CONCAT(DISTINCT n.name ORDER BY mn.is_primary DESC SEPARATOR ', ') AS node_names
             FROM members m
             LEFT JOIN member_nodes mn ON mn.member_id = m.id
             LEFT JOIN org_nodes n ON n.id = mn.node_id
             WHERE m.id = :id
             GROUP BY m.id",
            ['id' => $memberId]
        );

        if ($member === null) {
            fclose($handle);
            throw new \RuntimeException("Member $memberId not found");
        }

        // Remove encrypted medical notes from export (sensitive)
        unset($member['medical_notes']);

        Csv::put($handle, ['--- MEMBER PROFILE ---']);
        Csv::put($handle, array_keys($member));
        Csv::put($handle, array_values($member));
        Csv::put($handle, []);

        // ── Custom field data ──
        // Values live in the members.member_custom_data JSON column keyed by
        // field_key; labels come from custom_field_definitions
        $customValues = json_decode((string) ($member['member_custom_data'] ?? ''), true) ?: [];

        if (!empty($customValues)) {
            $definitions = $this->db->fetchAll(
                "SELECT field_key, label FROM custom_field_definitions ORDER BY sort_order ASC"
            );
            $labels = array_column($definitions, 'label', 'field_key');

            Csv::put($handle, ['--- CUSTOM FIELDS ---']);
            Csv::put($handle, ['field_key', 'label', 'value']);
            foreach ($customValues as $fieldKey => $value) {
                Csv::put($handle, [
                    $fieldKey,
                    $labels[$fieldKey] ?? $fieldKey,
                    is_scalar($value) ? (string) $value : json_encode($value),
                ]);
            }
            Csv::put($handle, []);
        }

        // ── Timeline entries ──
        $timeline = $this->db->fetchAll(
            "SELECT t.id, t.field_key, t.value, t.effective_date, t.notes, t.created_at,
                    u.email AS recorded_by_email
             FROM member_timeline t
             LEFT JOIN users u ON u.id = t.recorded_by
             WHERE t.member_id = :id
             ORDER BY t.effective_date DESC, t.created_at DESC",
            ['id' => $memberId]
        );

        if (!empty($timeline)) {
            Csv::put($handle, ['--- TIMELINE ---']);
            Csv::put($handle, ['id', 'field_key', 'value', 'effective_date', 'notes', 'created_at', 'recorded_by_email']);
            foreach ($timeline as $row) {
                Csv::put($handle, [
                    $row['id'], $row['field_key'], $row['value'], $row['effective_date'],
                    $row['notes'] ?? '', $row['created_at'], $row['recorded_by_email'] ?? '',
                ]);
            }
            Csv::put($handle, []);
        }

        // ── Role assignments ──
        // Assignments hang off the linked user account, scoped to a node or team
        $roles = [];
        if (!empty($member['user_id'])) {
            $roles = $this->db->fetchAll(
                "SELECT ra.id, r.name AS role_name,
                        CASE
                            WHEN ra.context_type = 'node' THEN (SELECT name FROM org_nodes WHERE id = ra.context_id)
                            WHEN ra.context_type = 'team' THEN (SELECT name FROM org_teams WHERE id = ra.context_id)
                            ELSE 'Global'
                        END AS context_name,
                        ra.start_date, ra.end_date
                 FROM role_assignments ra
                 JOIN roles r ON r.id = ra.role_id
                 WHERE ra.user_id = :uid
                 ORDER BY ra.start_date DESC",
                ['uid' => (int) $member['user_id']]
            );
        }

        if (!empty($roles)) {
            Csv::put($handle, ['--- ROLE ASSIGNMENTS ---']);
            Csv::put($handle, ['id', 'role_name', 'context_name', 'start_date', 'end_date']);
            foreach ($roles as $row) {
                Csv::put($handle, [
                    $row['id'], $row['role_name'], $row['context_name'] ?? '',
                    $row['start_date'], $row['end_date'] ?? '',
                ]);
            }
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv !== false ? $csv : '';
    }

    /**
     * Export all system settings as formatted JSON.
     *
     * @return string JSON string
     */
    public function exportSettingsJson(): string
    {
        $rows = $this->db->fetchAll(
            "SELECT `key`, `value`, `group` FROM settings ORDER BY `group` ASC, `key` ASC"
        );

        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['group']][$row['key']] = $row['value'];
        }

        return json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    // ──── Private helpers ────

    /**
     * Fetch members with node names, optionally scoped by node IDs.
     *
     * @param array|null $nodeIds Node IDs to filter by
     * @return array Member rows
     */
    private function fetchMembers(?array $nodeIds): array
    {
        $where = '';
        $params = [];

        if ($nodeIds !== null && !empty($nodeIds)) {
            $placeholders = [];
            foreach ($nodeIds as $i => $nodeId) {
                $key = "node_$i";
                $placeholders[] = ":$key";
                $params[$key] = $nodeId;
            }
            $where = "HAVING MAX(CASE WHEN mn.node_id IN (" . implode(',', $placeholders) . ") THEN 1 ELSE 0 END) = 1";
        }

        return $this->db->fetchAll(
            "SELECT m.membership_number, m.first_name, m.surname, m.email, m.phone,
                    m.dob, m.gender, m.status, m.joined_date,
                    GROUP_CONCAT(DISTINCT n.name ORDER BY mn.is_primary DESC SEPARATOR ', ') AS node_names
             FROM members m
             LEFT JOIN member_nodes mn ON mn.member_id = m.id
             LEFT JOIN org_nodes n ON n.id = mn.node_id
             GROUP BY m.id
             $where
             ORDER BY m.surname ASC, m.first_name ASC",
            $params
        );
    }

    /**
     * Escape a value for safe inclusion in XML.
     *
     * @param string $value Raw value
     * @return string Escaped value
     */
    private function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

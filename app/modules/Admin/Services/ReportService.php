<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Core\Csv;
use App\Core\Database;

/**
 * Reporting service.
 *
 * Provides analytical queries for member growth trends, demographic
 * breakdowns, role distribution, status change history, and CSV
 * export. All methods return structured arrays suitable for rendering
 * in charts or tables.
 */
class ReportService
{
    private Database $db;

    /** @var array Age group definitions: label => [min, max] (inclusive) */
    private const AGE_GROUPS = [
        'Under 8'  => [0, 7],
        '8-11'     => [8, 11],
        '12-15'    => [12, 15],
        '16-18'    => [16, 18],
        '19-25'    => [19, 25],
        'Over 25'  => [26, 200],
    ];

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Get member growth over time.
     *
     * Counts members grouped by their joined_date, truncated to the
     * requested interval (month, quarter, or year). Results are ordered
     * chronologically.
     *
     * @param string $interval Grouping interval: 'month', 'quarter', or 'year'
     * @param string|null $startDate Start of date range (Y-m-d), or null for no lower bound
     * @param string|null $endDate End of date range (Y-m-d), or null for no upper bound
     * @return array List of [{period, count}]
     * @throws \InvalidArgumentException if interval is invalid
     */
    public function getMemberGrowth(string $interval = 'month', ?string $startDate = null, ?string $endDate = null, array $scopeNodeIds = []): array
    {
        $truncExpr = match ($interval) {
            'month'   => "DATE_FORMAT(m.joined_date, '%Y-%m')",
            'quarter' => "CONCAT(YEAR(m.joined_date), '-Q', QUARTER(m.joined_date))",
            'year'    => "YEAR(m.joined_date)",
            default   => throw new \InvalidArgumentException("Invalid interval: {$interval}. Must be month, quarter, or year."),
        };

        $conditions = ["m.joined_date IS NOT NULL"];
        $params = [];

        if ($startDate !== null) {
            $conditions[] = "m.joined_date >= :start_date";
            $params['start_date'] = $startDate;
        }

        if ($endDate !== null) {
            $conditions[] = "m.joined_date <= :end_date";
            $params['end_date'] = $endDate;
        }

        if (!empty($scopeNodeIds)) {
            $placeholders = [];
            foreach ($scopeNodeIds as $i => $id) {
                $key = "s_$i";
                $placeholders[] = ":$key";
                $params[$key] = (int) $id;
            }
            $conditions[] = "EXISTS (SELECT 1 FROM member_nodes mn WHERE mn.member_id = m.id AND mn.node_id IN (" . implode(',', $placeholders) . "))";
        }

        $where = implode(' AND ', $conditions);

        $rows = $this->db->fetchAll(
            "SELECT {$truncExpr} AS period, COUNT(*) AS count
             FROM `members` m
             WHERE {$where}
             GROUP BY period
             ORDER BY period ASC",
            $params
        );

        // Cast count to int
        return array_map(fn(array $row) => [
            'period' => (string) $row['period'],
            'count' => (int) $row['count'],
        ], $rows);
    }

    /**
     * Get member demographics.
     *
     * Returns breakdowns by gender and by age group. Age is calculated
     * from the member's date of birth. Members without a DOB are placed
     * in the "Unknown" age group.
     *
     * @return array{by_gender: array, by_age_group: array}
     */
    public function getDemographics(array $scopeNodeIds = []): array
    {
        return [
            'by_gender' => $this->getGenderBreakdown($scopeNodeIds),
            'by_age_group' => $this->getAgeGroupBreakdown($scopeNodeIds),
        ];
    }

    /**
     * Build an "EXISTS (SELECT ... member_nodes WHERE node_id IN (...))"
     * clause + params for scope filtering. Returns [clause, params].
     *
     * @param array<int, int> $scopeNodeIds
     * @return array{0: string, 1: array<string, int>}
     */
    private function scopeClause(array $scopeNodeIds, string $memberAlias = 'm'): array
    {
        if (empty($scopeNodeIds)) {
            return ['', []];
        }
        $params = [];
        $placeholders = [];
        foreach ($scopeNodeIds as $i => $id) {
            $key = "s_$i";
            $placeholders[] = ":$key";
            $params[$key] = (int) $id;
        }
        $clause = "EXISTS (SELECT 1 FROM member_nodes mn WHERE mn.member_id = {$memberAlias}.id AND mn.node_id IN (" . implode(',', $placeholders) . "))";
        return [$clause, $params];
    }

    /**
     * Get a summary of active role assignments grouped by role name.
     *
     * @return array List of [{role, count}]
     */
    public function getRolesSummary(array $scopeNodeIds = []): array
    {
        $where = '';
        $params = [];
        if (!empty($scopeNodeIds)) {
            $placeholders = [];
            foreach ($scopeNodeIds as $i => $id) {
                $key = "s_$i";
                $placeholders[] = ":$key";
                $params[$key] = (int) $id;
            }
            // Only count assignments anchored at a node inside the scope.
            $where = "WHERE ra.context_type = 'node' AND ra.context_id IN (" . implode(',', $placeholders) . ")";
        }
        $rows = $this->db->fetchAll(
            "SELECT r.name AS role, COUNT(*) AS count
             FROM `role_assignments` ra
             JOIN `roles` r ON r.id = ra.role_id
             {$where}
             GROUP BY r.id, r.name
             ORDER BY count DESC, r.name ASC",
            $params
        );

        return array_map(fn(array $row) => [
            'role' => $row['role'],
            'count' => (int) $row['count'],
        ], $rows);
    }

    /**
     * Get members whose status changed within a date range.
     *
     * Queries the audit_log for member status_change events. Each entry
     * includes the member details and the before/after status values.
     *
     * @param string|null $startDate Start of date range (Y-m-d), or null for no lower bound
     * @param string|null $endDate End of date range (Y-m-d), or null for no upper bound
     * @return array List of status change records
     */
    public function getStatusChanges(?string $startDate = null, ?string $endDate = null, array $scopeNodeIds = []): array
    {
        $conditions = [
            "al.entity_type = 'member'",
            "al.action = 'status_change'",
        ];
        $params = [];

        if ($startDate !== null) {
            $conditions[] = "al.created_at >= :start_date";
            $params['start_date'] = $startDate . ' 00:00:00';
        }

        if ($endDate !== null) {
            $conditions[] = "al.created_at <= :end_date";
            $params['end_date'] = $endDate . ' 23:59:59';
        }

        if (!empty($scopeNodeIds)) {
            [$clause, $scopeParams] = $this->scopeClause($scopeNodeIds);
            $conditions[] = $clause;
            $params += $scopeParams;
        }

        $where = implode(' AND ', $conditions);

        return $this->db->fetchAll(
            "SELECT al.entity_id AS member_id,
                    m.first_name, m.surname, m.membership_number,
                    al.old_values, al.new_values,
                    al.created_at AS changed_at,
                    u.email AS changed_by_email
             FROM `audit_log` al
             LEFT JOIN `members` m ON m.id = al.entity_id
             LEFT JOIN `users` u ON u.id = al.user_id
             WHERE {$where}
             ORDER BY al.created_at DESC",
            $params
        );
    }

    /**
     * Export members as a CSV string.
     *
     * Optionally scoped to members belonging to specific org nodes.
     * Returns a complete CSV document including a header row.
     *
     * @param int[]|null $nodeIds Limit to members in these nodes (null = all members)
     * @return string The CSV content
     */
    public function exportMembersCsv(?array $nodeIds = null): string
    {
        $conditions = [];
        $params = [];

        if ($nodeIds !== null && count($nodeIds) > 0) {
            $placeholders = [];
            foreach ($nodeIds as $i => $nodeId) {
                $key = "node_{$i}";
                $placeholders[] = ":{$key}";
                $params[$key] = $nodeId;
            }
            $inClause = implode(', ', $placeholders);
            $conditions[] = "EXISTS (
                SELECT 1 FROM `member_nodes` mn
                WHERE mn.member_id = m.id
                AND mn.node_id IN ({$inClause})
            )";
        }

        $where = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $members = $this->db->fetchAll(
            "SELECT m.membership_number, m.first_name, m.surname, m.dob, m.gender,
                    m.email, m.phone, m.address_line1, m.address_line2, m.city,
                    m.postcode, m.country, m.status, m.joined_date, m.left_date,
                    GROUP_CONCAT(DISTINCT n.name ORDER BY mn.is_primary DESC SEPARATOR '; ') AS nodes
             FROM `members` m
             LEFT JOIN `member_nodes` mn ON mn.member_id = m.id
             LEFT JOIN `org_nodes` n ON n.id = mn.node_id
             {$where}
             GROUP BY m.id
             ORDER BY m.surname ASC, m.first_name ASC",
            $params
        );

        $headers = [
            'Membership Number', 'First Name', 'Surname', 'Date of Birth', 'Gender',
            'Email', 'Phone', 'Address Line 1', 'Address Line 2', 'City',
            'Postcode', 'Country', 'Status', 'Joined Date', 'Left Date', 'Nodes',
        ];

        $output = fopen('php://temp', 'r+');
        Csv::put($output, $headers);

        foreach ($members as $member) {
            Csv::put($output, [
                $member['membership_number'],
                $member['first_name'],
                $member['surname'],
                $member['dob'],
                $member['gender'],
                $member['email'],
                $member['phone'],
                $member['address_line1'],
                $member['address_line2'],
                $member['city'],
                $member['postcode'],
                $member['country'],
                $member['status'],
                $member['joined_date'],
                $member['left_date'],
                $member['nodes'],
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    // ──── Internal helpers ──────────────────────────────────────────

    /**
     * Get member counts grouped by gender.
     *
     * @return array List of [{gender, count}]
     */
    private function getGenderBreakdown(array $scopeNodeIds = []): array
    {
        [$clause, $params] = $this->scopeClause($scopeNodeIds);
        $where = $clause !== '' ? "WHERE {$clause}" : '';
        $rows = $this->db->fetchAll(
            "SELECT COALESCE(m.`gender`, 'Unknown') AS gender, COUNT(*) AS count
             FROM `members` m
             {$where}
             GROUP BY gender
             ORDER BY count DESC",
            $params
        );

        return array_map(fn(array $row) => [
            'gender' => $row['gender'],
            'count' => (int) $row['count'],
        ], $rows);
    }

    /**
     * Get member counts grouped by age bracket.
     *
     * Age is computed from dob using TIMESTAMPDIFF. Members without a DOB
     * are counted under "Unknown".
     *
     * @return array List of [{group, count}]
     */
    private function getAgeGroupBreakdown(array $scopeNodeIds = []): array
    {
        [$clause, $params] = $this->scopeClause($scopeNodeIds);
        $where = $clause !== '' ? "WHERE {$clause}" : '';
        $sql = "
            SELECT
                CASE
                    WHEN m.dob IS NULL THEN 'Unknown'
                    WHEN TIMESTAMPDIFF(YEAR, m.dob, CURDATE()) < 8 THEN 'Under 8'
                    WHEN TIMESTAMPDIFF(YEAR, m.dob, CURDATE()) BETWEEN 8 AND 11 THEN '8-11'
                    WHEN TIMESTAMPDIFF(YEAR, m.dob, CURDATE()) BETWEEN 12 AND 15 THEN '12-15'
                    WHEN TIMESTAMPDIFF(YEAR, m.dob, CURDATE()) BETWEEN 16 AND 18 THEN '16-18'
                    WHEN TIMESTAMPDIFF(YEAR, m.dob, CURDATE()) BETWEEN 19 AND 25 THEN '19-25'
                    ELSE 'Over 25'
                END AS age_group,
                COUNT(*) AS count
            FROM `members` m
            {$where}
            GROUP BY age_group
            ORDER BY FIELD(age_group, 'Under 8', '8-11', '12-15', '16-18', '19-25', 'Over 25', 'Unknown')
        ";

        $rows = $this->db->fetchAll($sql, $params);

        return array_map(fn(array $row) => [
            'group' => $row['age_group'],
            'count' => (int) $row['count'],
        ], $rows);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Directory\Services;

use App\Core\Database;

/**
 * Directory service.
 *
 * Returns active members within the caller's scope along with their
 * current role assignments, for the contact directory page. Leaders
 * use this to look up contact details for people in their nodes.
 */
class DirectoryService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Get active members within the given scope, with aggregated role
     * names and primary node name. Filterable by free-text search and
     * a single node.
     *
     * @param array       $scopeNodeIds Node IDs the caller may see (already subtree-expanded)
     * @param string|null $search       Free-text match on name / email
     * @param int|null    $nodeId       Restrict to members linked to this node
     * @return array Rows: id, member_name, email, phone, photo_path, primary_node_name, role_names
     */
    public function getDirectoryMembers(array $scopeNodeIds, ?string $search = null, ?int $nodeId = null): array
    {
        $conditions = ["m.status = 'active'"];
        $params = [];

        if (!empty($scopeNodeIds)) {
            $placeholders = [];
            foreach ($scopeNodeIds as $i => $id) {
                $key = "scope_$i";
                $placeholders[] = ":$key";
                $params[$key] = (int) $id;
            }
            $conditions[] = 'mn.node_id IN (' . implode(',', $placeholders) . ')';
        }

        if ($nodeId !== null) {
            $conditions[] = 'mn.node_id = :node_id';
            $params['node_id'] = $nodeId;
        }

        if ($search !== null && trim($search) !== '') {
            $conditions[] = "(
                m.first_name LIKE :search1
                OR m.surname LIKE :search2
                OR CONCAT(m.first_name, ' ', m.surname) LIKE :search3
                OR m.email LIKE :search4
            )";
            $like = '%' . trim($search) . '%';
            $params['search1'] = $like;
            $params['search2'] = $like;
            $params['search3'] = $like;
            $params['search4'] = $like;
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);

        return $this->db->fetchAll(
            "SELECT m.id,
                    CONCAT(m.first_name, ' ', m.surname) AS member_name,
                    m.first_name,
                    m.surname,
                    m.email,
                    m.phone,
                    m.photo_path,
                    (
                        SELECT n2.name
                        FROM member_nodes mn2
                        JOIN org_nodes n2 ON n2.id = mn2.node_id
                        WHERE mn2.member_id = m.id AND mn2.is_primary = 1
                        LIMIT 1
                    ) AS primary_node_name,
                    (
                        SELECT GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR ', ')
                        FROM role_assignments ra
                        JOIN roles r ON r.id = ra.role_id
                        WHERE ra.user_id = m.user_id
                          AND ra.start_date <= CURDATE()
                          AND (ra.end_date IS NULL OR ra.end_date >= CURDATE())
                    ) AS role_names
             FROM members m
             JOIN member_nodes mn ON mn.member_id = m.id
             $where
             GROUP BY m.id
             ORDER BY m.surname ASC, m.first_name ASC",
            $params
        );
    }

    /**
     * Legacy role-holder list (directory-visible roles only). Retained
     * because external callers / reports may rely on it.
     *
     * @return array Flat list of contacts.
     */
    public function getContactDirectory(?int $nodeId = null, ?string $search = null, array $scopeNodeIds = []): array
    {
        $conditions = [
            "r.is_directory_visible = 1",
            "ra.start_date <= CURDATE()",
            "(ra.end_date IS NULL OR ra.end_date >= CURDATE())",
            "ra.context_type = 'node'",
        ];
        $params = [];

        if ($nodeId !== null) {
            $conditions[] = "ra.context_id = :node_id";
            $params['node_id'] = $nodeId;
        }

        if (!empty($scopeNodeIds)) {
            $placeholders = [];
            foreach ($scopeNodeIds as $i => $id) {
                $key = "scope_$i";
                $placeholders[] = ":$key";
                $params[$key] = (int) $id;
            }
            $conditions[] = 'ra.context_id IN (' . implode(',', $placeholders) . ')';
        }

        if ($search !== null && trim($search) !== '') {
            $conditions[] = "(
                m.first_name LIKE :search1
                OR m.surname LIKE :search2
                OR CONCAT(m.first_name, ' ', m.surname) LIKE :search3
                OR r.name LIKE :search4
                OR n.name LIKE :search5
            )";
            $like = '%' . trim($search) . '%';
            $params['search1'] = $like;
            $params['search2'] = $like;
            $params['search3'] = $like;
            $params['search4'] = $like;
            $params['search5'] = $like;
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);

        return $this->db->fetchAll(
            "SELECT CONCAT(m.first_name, ' ', m.surname) AS member_name,
                    r.name AS role_name,
                    m.email,
                    m.phone,
                    n.name AS node_name
             FROM role_assignments ra
             JOIN roles r ON r.id = ra.role_id
             JOIN users u ON u.id = ra.user_id
             JOIN members m ON m.user_id = u.id
             JOIN org_nodes n ON n.id = ra.context_id
             $where
             ORDER BY n.name ASC, r.name ASC, m.surname ASC, m.first_name ASC",
            $params
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Core\Database;

/**
 * Event management service.
 *
 * Handles CRUD operations for calendar events, including publishing
 * workflow, date-range queries for calendar views, upcoming event
 * listings, and node-scope filtering.
 */
class EventService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Create a new event.
     *
     * @param array $data Event data (title, start_date required; description, location,
     *                     end_date, all_day, node_scope_id optional)
     * @param int $createdBy The ID of the user creating the event
     * @return int The new event ID
     * @throws \InvalidArgumentException if required fields are missing
     */
    public function create(array $data, int $createdBy): int
    {
        if (empty(trim($data['title'] ?? ''))) {
            throw new \InvalidArgumentException('Title is required');
        }
        if (empty(trim($data['start_date'] ?? ''))) {
            throw new \InvalidArgumentException('Start date is required');
        }

        return $this->db->insert('events', [
            'title' => trim($data['title']),
            'description' => $data['description'] ?? null,
            'location' => isset($data['location']) ? trim($data['location']) : null,
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'] ?? null,
            'all_day' => (int) ($data['all_day'] ?? 0),
            'node_scope_id' => $data['node_scope_id'] ?? null,
            'created_by' => $createdBy,
            'is_published' => 0,
        ]);
    }

    /**
     * Update an existing event.
     *
     * @param int $id Event ID
     * @param array $data Fields to update (title, description, location, start_date,
     *                     end_date, all_day, node_scope_id)
     * @throws \InvalidArgumentException if title or start_date is set but empty
     */
    public function update(int $id, array $data): void
    {
        $updateData = [];

        if (array_key_exists('title', $data)) {
            if (empty(trim($data['title']))) {
                throw new \InvalidArgumentException('Title cannot be empty');
            }
            $updateData['title'] = trim($data['title']);
        }

        if (array_key_exists('description', $data)) {
            $updateData['description'] = $data['description'];
        }

        if (array_key_exists('location', $data)) {
            $updateData['location'] = $data['location'] !== null ? trim($data['location']) : null;
        }

        if (array_key_exists('start_date', $data)) {
            if (empty(trim($data['start_date']))) {
                throw new \InvalidArgumentException('Start date cannot be empty');
            }
            $updateData['start_date'] = $data['start_date'];
        }

        if (array_key_exists('end_date', $data)) {
            $updateData['end_date'] = $data['end_date'];
        }

        if (array_key_exists('all_day', $data)) {
            $updateData['all_day'] = (int) $data['all_day'];
        }

        if (array_key_exists('node_scope_id', $data)) {
            $updateData['node_scope_id'] = $data['node_scope_id'];
        }

        if (!empty($updateData)) {
            $this->db->update('events', $updateData, ['id' => $id]);
        }
    }

    /**
     * Delete an event.
     *
     * @param int $id Event ID
     */
    public function delete(int $id): void
    {
        $this->db->delete('events', ['id' => $id]);
    }

    /**
     * Get an event by its ID.
     *
     * @param int $id Event ID
     * @return array|null Event data or null if not found
     */
    public function getById(int $id): ?array
    {
        $event = $this->db->fetchOne(
            "SELECT e.*, u.email AS created_by_email
             FROM events e
             LEFT JOIN users u ON u.id = e.created_by
             WHERE e.id = :id",
            ['id' => $id]
        );

        return $event !== null ? $this->castTypes($event) : null;
    }

    /**
     * Publish an event.
     *
     * Sets is_published to 1.
     *
     * @param int $id Event ID
     */
    public function publish(int $id): void
    {
        $this->db->update('events', [
            'is_published' => 1,
        ], ['id' => $id]);
    }

    /**
     * Unpublish an event.
     *
     * Sets is_published to 0.
     *
     * @param int $id Event ID
     */
    public function unpublish(int $id): void
    {
        $this->db->update('events', [
            'is_published' => 0,
        ], ['id' => $id]);
    }

    /**
     * Get upcoming published events.
     *
     * Returns published events with start_date >= NOW(), ordered by start_date
     * ascending. Pass nodeIds to limit to those nodes + global events; pass
     * null (default) to return all published events regardless of scope.
     *
     * @param int[]|null $nodeIds Nodes to include (null = no filter; [] = global only)
     * @param int $limit Maximum number of events to return
     * @return array List of upcoming events
     */
    public function getUpcoming(?array $nodeIds = null, int $limit = 10): array
    {
        $conditions = "e.is_published = 1 AND e.start_date >= NOW()";
        $params = [];

        $conditions .= $this->buildNodeScopeCondition($nodeIds, $params);

        $params['limit'] = $limit;

        $events = $this->db->fetchAll(
            "SELECT e.*, u.email AS created_by_email
             FROM events e
             LEFT JOIN users u ON u.id = e.created_by
             WHERE $conditions
             ORDER BY e.start_date ASC
             LIMIT :limit",
            $params
        );

        return array_map([$this, 'castTypes'], $events);
    }

    /**
     * Get published events within a date range.
     *
     * Returns events whose start_date falls within the given range. Used for
     * calendar views. Pass nodeIds to restrict to those nodes + global events;
     * pass null (default) to return all events regardless of scope.
     *
     * @param string $startDate Range start (Y-m-d or Y-m-d H:i:s)
     * @param string $endDate Range end (Y-m-d or Y-m-d H:i:s)
     * @param int[]|null $nodeIds Nodes to include (null = no filter; [] = global only)
     * @return array List of events in the date range
     */
    public function getForDateRange(string $startDate, string $endDate, ?array $nodeIds = null): array
    {
        $conditions = "e.is_published = 1 AND e.start_date >= :range_start AND e.start_date <= :range_end";
        $params = [
            'range_start' => $startDate,
            'range_end' => $endDate,
        ];

        $conditions .= $this->buildNodeScopeCondition($nodeIds, $params);

        $events = $this->db->fetchAll(
            "SELECT e.*, u.email AS created_by_email
             FROM events e
             LEFT JOIN users u ON u.id = e.created_by
             WHERE $conditions
             ORDER BY e.start_date ASC",
            $params
        );

        return array_map([$this, 'castTypes'], $events);
    }

    /**
     * Get all events for admin view (regardless of published status).
     *
     * Returns paginated results ordered by created_at descending.
     *
     * @param int $page Current page (1-based)
     * @param int $perPage Items per page
     * @return array{items: array, total: int, page: int, pages: int, per_page: int}
     */
    public function getAll(int $page = 1, int $perPage = 20, ?int $year = null, ?int $month = null, array $scopeNodeIds = []): array
    {
        $conditions = '1=1';
        $params = [];
        if ($year !== null) {
            $conditions .= ' AND YEAR(e.start_date) = :year';
            $params['year'] = $year;
        }
        if ($month !== null) {
            $conditions .= ' AND MONTH(e.start_date) = :month';
            $params['month'] = $month;
        }
        if (!empty($scopeNodeIds)) {
            // Include events scoped to any given node OR org-wide (null).
            $placeholders = [];
            foreach ($scopeNodeIds as $i => $id) {
                $key = "n_$i";
                $placeholders[] = ":$key";
                $params[$key] = (int) $id;
            }
            $conditions .= ' AND (e.node_scope_id IN (' . implode(',', $placeholders) . ') OR e.node_scope_id IS NULL)';
        }

        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM events e WHERE $conditions",
            $params
        );

        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        $items = $this->db->fetchAll(
            "SELECT e.*, u.email AS created_by_email
             FROM events e
             LEFT JOIN users u ON u.id = e.created_by
             WHERE $conditions
             ORDER BY e.start_date DESC
             LIMIT :limit OFFSET :offset",
            $params + ['limit' => $perPage, 'offset' => $offset]
        );

        $items = array_map([$this, 'castTypes'], $items);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
        ];
    }

    /**
     * Get the distinct years that have events, sorted descending.
     *
     * @return int[]
     */
    public function getDistinctYears(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT DISTINCT YEAR(start_date) AS y FROM events ORDER BY y DESC"
        );
        return array_map(fn($r) => (int) $r['y'], $rows);
    }

    /**
     * Get published events for a specific calendar month.
     *
     * Convenience wrapper around getForDateRange that calculates the first and
     * last moment of the given month.
     *
     * @param int $year Four-digit year
     * @param int $month Month number (1-12)
     * @param int[]|null $nodeIds Nodes to include (null = no filter; [] = global only)
     * @return array List of events in the month
     */
    public function getForMonth(int $year, int $month, ?array $nodeIds = null): array
    {
        $startDate = sprintf('%04d-%02d-01 00:00:00', $year, $month);
        $endDate = date('Y-m-t 23:59:59', mktime(0, 0, 0, $month, 1, $year));

        return $this->getForDateRange($startDate, $endDate, $nodeIds);
    }

    /**
     * Build a SQL condition fragment for node scope filtering.
     *
     * - null  → no filter; all events (global and scoped) are returned.
     * - []    → only globally-scoped events (node_scope_id IS NULL).
     * - [1,2] → events scoped to those nodes OR globally scoped.
     *
     * @param int[]|null $nodeIds Node IDs to include, or null for no filter
     * @param array &$params Query parameters array (modified in place)
     * @return string SQL fragment to append to WHERE clause
     */
    private function buildNodeScopeCondition(?array $nodeIds, array &$params): string
    {
        if ($nodeIds === null) {
            return '';
        }

        if (count($nodeIds) > 0) {
            $placeholders = [];
            foreach ($nodeIds as $i => $nodeId) {
                $key = "node_id_$i";
                $placeholders[] = ":$key";
                $params[$key] = $nodeId;
            }
            $inClause = implode(', ', $placeholders);
            return " AND (e.node_scope_id IN ($inClause) OR e.node_scope_id IS NULL)";
        }

        return " AND e.node_scope_id IS NULL";
    }

    /**
     * Cast database types on an event row.
     *
     * Ensures integer and boolean fields are returned with correct PHP types
     * rather than strings from the PDO result set.
     *
     * @param array $event Raw event row from database
     * @return array Event row with properly typed values
     */
    private function castTypes(array $event): array
    {
        if (isset($event['id'])) {
            $event['id'] = (int) $event['id'];
        }
        if (array_key_exists('created_by', $event)) {
            $event['created_by'] = $event['created_by'] !== null ? (int) $event['created_by'] : null;
        }
        if (array_key_exists('node_scope_id', $event)) {
            $event['node_scope_id'] = $event['node_scope_id'] !== null ? (int) $event['node_scope_id'] : null;
        }
        if (isset($event['is_published'])) {
            $event['is_published'] = (bool) $event['is_published'];
        }
        if (isset($event['all_day'])) {
            $event['all_day'] = (bool) $event['all_day'];
        }

        return $event;
    }
}

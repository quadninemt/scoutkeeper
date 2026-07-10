<?php

declare(strict_types=1);

namespace App\Modules\Communications\Services;

use App\Core\Database;
use App\Core\HtmlSanitizer;

/**
 * Article management service.
 *
 * Handles CRUD operations for articles/news posts, including slug
 * generation, publishing workflow, and paginated retrieval with
 * optional node-scope filtering.
 */
class ArticleService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Create a new article.
     *
     * @param array $data Article data (title, body required; excerpt, visibility, node_scope_id optional)
     * @param int $authorId The ID of the user creating the article
     * @return int The new article ID
     * @throws \InvalidArgumentException if required fields are missing
     */
    public function create(array $data, int $authorId): int
    {
        if (empty(trim($data['title'] ?? ''))) {
            throw new \InvalidArgumentException('Title is required');
        }
        if (empty(trim($data['body'] ?? ''))) {
            throw new \InvalidArgumentException('Body is required');
        }

        $slug = $this->generateSlug($data['title']);

        return $this->db->insert('articles', [
            'title' => trim($data['title']),
            'slug' => $slug,
            'body' => HtmlSanitizer::sanitize($data['body']),
            'excerpt' => $data['excerpt'] ?? null,
            'visibility' => $data['visibility'] ?? 'members',
            'is_published' => 0,
            'author_id' => $authorId,
            'node_scope_id' => $data['node_scope_id'] ?? null,
        ]);
    }

    /**
     * Update an existing article.
     *
     * @param int $id Article ID
     * @param array $data Fields to update (title, body, excerpt, visibility, node_scope_id)
     * @throws \InvalidArgumentException if title or body is set but empty
     */
    public function update(int $id, array $data): void
    {
        $updateData = [];

        if (array_key_exists('title', $data)) {
            if (empty(trim($data['title']))) {
                throw new \InvalidArgumentException('Title cannot be empty');
            }
            $updateData['title'] = trim($data['title']);
            $updateData['slug'] = $this->generateSlug($data['title'], $id);
        }

        if (array_key_exists('body', $data)) {
            if (empty(trim($data['body']))) {
                throw new \InvalidArgumentException('Body cannot be empty');
            }
            $updateData['body'] = HtmlSanitizer::sanitize($data['body']);
        }

        if (array_key_exists('excerpt', $data)) {
            $updateData['excerpt'] = $data['excerpt'];
        }

        if (array_key_exists('visibility', $data)) {
            $allowed = ['public', 'members', 'portal'];
            if (!in_array($data['visibility'], $allowed, true)) {
                throw new \InvalidArgumentException('Invalid visibility value');
            }
            $updateData['visibility'] = $data['visibility'];
        }

        if (array_key_exists('node_scope_id', $data)) {
            $updateData['node_scope_id'] = $data['node_scope_id'];
        }

        if (!empty($updateData)) {
            $this->db->update('articles', $updateData, ['id' => $id]);
        }
    }

    /**
     * Publish an article.
     *
     * Sets is_published to 1 and published_at to the current timestamp.
     *
     * @param int $id Article ID
     */
    public function publish(int $id): void
    {
        $this->db->update('articles', [
            'is_published' => 1,
            'published_at' => gmdate('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    /**
     * Unpublish an article.
     *
     * Sets is_published to 0 but retains published_at for historical reference.
     *
     * @param int $id Article ID
     */
    public function unpublish(int $id): void
    {
        $this->db->update('articles', [
            'is_published' => 0,
        ], ['id' => $id]);
    }

    /**
     * Delete an article.
     *
     * @param int $id Article ID
     */
    public function delete(int $id): void
    {
        $this->db->delete('articles', ['id' => $id]);
    }

    /**
     * Get an article by its ID.
     *
     * @param int $id Article ID
     * @return array|null Article data or null if not found
     */
    public function getById(int $id): ?array
    {
        $article = $this->db->fetchOne(
            "SELECT a.*, u.email AS author_email
             FROM articles a
             LEFT JOIN users u ON u.id = a.author_id
             WHERE a.id = :id",
            ['id' => $id]
        );

        return $article !== null ? $this->castTypes($article) : null;
    }

    /**
     * Get an article by its slug.
     *
     * @param string $slug URL slug
     * @return array|null Article data or null if not found
     */
    public function getBySlug(string $slug): ?array
    {
        $article = $this->db->fetchOne(
            "SELECT a.*, u.email AS author_email
             FROM articles a
             LEFT JOIN users u ON u.id = a.author_id
             WHERE a.slug = :slug",
            ['slug' => $slug]
        );

        return $article !== null ? $this->castTypes($article) : null;
    }

    /**
     * Get published articles with pagination.
     *
     * Filters by node_scope_id when provided. Articles with node_scope_id = NULL
     * are visible to all nodes. Only returns published articles, ordered by
     * published_at descending.
     *
     * @param int|null $nodeId Filter by node scope (null = only globally scoped articles)
     * @param int $page Current page (1-based)
     * @param int $perPage Items per page
     * @return array{items: array, total: int, page: int, pages: int, per_page: int}
     */
    public function getPublished(?int $nodeId = null, int $page = 1, int $perPage = 10): array
    {
        $conditions = "a.is_published = 1";
        $params = [];

        if ($nodeId !== null) {
            // Show articles scoped to this node OR globally scoped (NULL)
            $conditions .= " AND (a.node_scope_id = :node_id OR a.node_scope_id IS NULL)";
            $params['node_id'] = $nodeId;
        } else {
            // No node specified — show only globally scoped articles
            $conditions .= " AND a.node_scope_id IS NULL";
        }

        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM articles a WHERE $conditions",
            $params
        );

        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        $items = $this->db->fetchAll(
            "SELECT a.*, u.email AS author_email
             FROM articles a
             LEFT JOIN users u ON u.id = a.author_id
             WHERE $conditions
             ORDER BY a.published_at DESC
             LIMIT :limit OFFSET :offset",
            array_merge($params, ['limit' => $perPage, 'offset' => $offset])
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
     * Get all articles for admin view (regardless of published status).
     *
     * @param int $page Current page (1-based)
     * @param int $perPage Items per page
     * @return array{items: array, total: int, page: int, pages: int, per_page: int}
     */
    public function getAll(int $page = 1, int $perPage = 20, array $scopeNodeIds = []): array
    {
        $where = '';
        $params = [];
        if (!empty($scopeNodeIds)) {
            // Scope-aware admin list: include articles scoped to any of the
            // given nodes PLUS org-wide articles (node_scope_id IS NULL).
            // Those must still be editable by any admin who can see them.
            $placeholders = [];
            foreach ($scopeNodeIds as $i => $id) {
                $key = "n_$i";
                $placeholders[] = ":$key";
                $params[$key] = (int) $id;
            }
            $where = "WHERE (a.node_scope_id IN (" . implode(',', $placeholders) . ") OR a.node_scope_id IS NULL) ";
        }

        $total = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM articles a {$where}", $params);

        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        $items = $this->db->fetchAll(
            "SELECT a.*, u.email AS author_email
             FROM articles a
             LEFT JOIN users u ON u.id = a.author_id
             {$where}
             ORDER BY a.created_at DESC
             LIMIT :limit OFFSET :offset",
            array_merge($params, ['limit' => $perPage, 'offset' => $offset])
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
     * Generate a unique URL slug from a title.
     *
     * Transliterates to ASCII, lowercases, replaces non-alphanumeric chars
     * with hyphens, and appends -2, -3, etc. if the slug already exists.
     *
     * @param string $title The article title
     * @param int|null $excludeId Article ID to exclude from uniqueness check (for updates)
     * @return string A unique slug
     */
    public function generateSlug(string $title, ?int $excludeId = null): string
    {
        // Transliterate to ASCII if possible
        $slug = transliterator_transliterate(
            'Any-Latin; Latin-ASCII; Lower()',
            $title
        ) ?: mb_strtolower($title, 'UTF-8');

        // Replace non-alphanumeric characters with hyphens
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);

        // Trim leading/trailing hyphens
        $slug = trim($slug, '-');

        // Ensure slug is not empty
        if ($slug === '') {
            $slug = 'article';
        }

        // Truncate to reasonable length (leave room for suffix)
        if (mb_strlen($slug) > 280) {
            $slug = mb_substr($slug, 0, 280);
            $slug = rtrim($slug, '-');
        }

        // Check uniqueness
        $baseSlug = $slug;
        $counter = 1;

        while (true) {
            $params = ['slug' => $slug];
            $sql = "SELECT COUNT(*) FROM articles WHERE slug = :slug";

            if ($excludeId !== null) {
                $sql .= " AND id != :exclude_id";
                $params['exclude_id'] = $excludeId;
            }

            $exists = (int) $this->db->fetchColumn($sql, $params);

            if ($exists === 0) {
                break;
            }

            $counter++;
            $slug = $baseSlug . '-' . $counter;
        }

        return $slug;
    }

    /**
     * Cast database types on an article row.
     */
    private function castTypes(array $article): array
    {
        if (isset($article['id'])) {
            $article['id'] = (int) $article['id'];
        }
        if (isset($article['author_id'])) {
            $article['author_id'] = $article['author_id'] !== null ? (int) $article['author_id'] : null;
        }
        if (isset($article['node_scope_id'])) {
            $article['node_scope_id'] = $article['node_scope_id'] !== null ? (int) $article['node_scope_id'] : null;
        }
        if (isset($article['is_published'])) {
            $article['is_published'] = (bool) $article['is_published'];
        }

        return $article;
    }
}

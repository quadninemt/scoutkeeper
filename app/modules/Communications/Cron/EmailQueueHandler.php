<?php

declare(strict_types=1);

namespace App\Modules\Communications\Cron;

use App\Core\Application;
use App\Core\CronHandlerInterface;
use App\Core\Logger;
use App\Modules\Communications\Services\EmailService;

/**
 * Cron handler that drains the outbound email queue.
 *
 * Registered by the Communications module and invoked by both the
 * cron entry point (cron/run.php) and the pseudo-cron fallback in
 * Application::runPseudoCron(). Batch size is configurable via
 * config['cron']['email_batch_size'].
 */
class EmailQueueHandler implements CronHandlerInterface
{
    public function execute(Application $app): void
    {
        $batchSize = (int) $app->getConfigValue('cron.email_batch_size', 20);
        $emailService = EmailService::create($app);

        $results = $emailService->processBatch($batchSize);

        if ($results['sent'] > 0 || $results['failed'] > 0) {
            Logger::info('Email queue processed', $results);
        }
    }
}

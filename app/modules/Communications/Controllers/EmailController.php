<?php

declare(strict_types=1);

namespace App\Modules\Communications\Controllers;

use App\Core\Controller;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Admin\Services\SettingsService;
use App\Modules\Communications\Services\EmailService;
use App\Modules\Communications\Services\EmailPreferenceService;
use App\Modules\OrgStructure\Services\OrgService;

/**
 * Email management controller.
 *
 * Compose/send emails to member groups, view email log and queue stats.
 */
class EmailController extends Controller
{
    private EmailService $emailService;
    private EmailPreferenceService $prefService;
    private OrgService $orgService;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->emailService = EmailService::create($app);
        $this->prefService = new EmailPreferenceService($app->getDb());
        $this->orgService = new OrgService($app->getDb());
    }

    /**
     * GET /admin/email — compose email form.
     */
    public function compose(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('communications.write');
        if ($guard !== null) {
            return $guard;
        }

        $nodes = $this->orgService->getTree();
        $stats = $this->emailService->getQueueStats();

        // Pre-select the active scope node so admins default to emailing
        // their own patch instead of the entire org (plan Q32).
        $ctx = $this->resolveViewContext();
        $preselectedNodeId = $ctx->activeScopeNodeId;

        return $this->render('@communications/email/compose.html.twig', [
            'nodes' => $nodes,
            'queue_stats' => $stats,
            'preselected_node_id' => $preselectedNodeId,
            'breadcrumbs' => [
                ['label' => $this->t('nav.communications'), 'url' => '/articles'],
                ['label' => $this->t('email.compose')],
            ],
        ]);
    }

    /**
     * POST /admin/email/send — queue email to selected recipients.
     */
    public function send(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('communications.write');
        if ($guard !== null) {
            return $guard;
        }

        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) {
            return $csrfCheck;
        }

        $subject = trim((string) $request->getParam('subject', ''));
        $body = (string) $request->getParam('body', '');
        $nodeIds = $request->getParam('node_ids', []);
        $mode = (string) $request->getParam('recipients_mode', 'me');

        if (empty($subject) || empty($body)) {
            $this->flash('error', $this->t('email.subject_body_required'));
            return $this->redirect('/admin/email');
        }

        if (!is_array($nodeIds)) {
            $nodeIds = $nodeIds ? [(int) $nodeIds] : [];
        }
        $nodeIds = array_map('intval', $nodeIds);

        if ($mode === 'selected' && empty($nodeIds)) {
            $this->flash('error', $this->t('email.select_nodes_required'));
            return $this->redirect('/admin/email');
        }

        if ($mode === 'me') {
            $user = $this->app->getSession()->getUser();
            if (empty($user['email'])) {
                $this->flash('error', $this->t('email.no_recipients'));
                return $this->redirect('/admin/email');
            }
            $name = trim(($user['first_name'] ?? '') . ' ' . ($user['surname'] ?? ''));
            $recipients = [[
                'email' => $user['email'],
                'first_name' => $user['first_name'] ?? '',
                'surname' => $user['surname'] ?? '',
            ]];
            if ($name === '') {
                $recipients[0]['first_name'] = $user['email'];
            }
        } else {
            $filterNodeIds = ($mode === 'selected' && !empty($nodeIds)) ? $nodeIds : null;
            $recipients = $this->prefService->getOptedInMembers('general', $filterNodeIds);
        }

        if (empty($recipients)) {
            $this->flash('error', $this->t('email.no_recipients'));
            return $this->redirect('/admin/email');
        }

        // Demo mode: restrict outbound email to the current admin's own address
        $settingsService = new SettingsService($this->app->getDb());
        if ($settingsService->get('install_mode') === 'demo') {
            $user = $this->app->getSession()->getUser();
            $selfEmail = strtolower((string) ($user['email'] ?? ''));
            $before = count($recipients);
            $recipients = array_values(array_filter(
                $recipients,
                fn($r) => strtolower((string) ($r['email'] ?? '')) === $selfEmail
            ));
            $blocked = $before - count($recipients);
            if ($blocked > 0) {
                $this->flash('warning', $this->t('settings.smtp_demo_blocked', [
                    'email' => $selfEmail,
                    'blocked' => $blocked,
                ]));
            }
            if (empty($recipients)) {
                $this->flash('error', $this->t('email.no_recipients'));
                return $this->redirect('/admin/email');
            }
        }

        $recipientList = array_map(fn($r) => [
            'email' => $r['email'],
            'name' => $r['first_name'] . ' ' . $r['surname'],
        ], $recipients);

        $bodyText = strip_tags($body);
        $count = $this->emailService->queueBulk($recipientList, $subject, $body, $bodyText);

        $this->flash('success', $this->t('email.queued', ['count' => $count]));
        return $this->redirect('/admin/email/log');
    }

    /**
     * GET /admin/email/log — email log.
     */
    public function log(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('communications.write');
        if ($guard !== null) {
            return $guard;
        }

        $page = max(1, (int) $request->getParam('page', 1));
        $result = $this->emailService->getLog($page, 25);
        $stats = $this->emailService->getQueueStats();

        return $this->render('@communications/email/log.html.twig', [
            'entries' => $result['items'],
            'pagination' => $result,
            'queue_stats' => $stats,
            'breadcrumbs' => [
                ['label' => $this->t('nav.communications'), 'url' => '/articles'],
                ['label' => $this->t('email.log')],
            ],
        ]);
    }

    private function t(string $key, array $params = []): string
    {
        return $this->app->getI18n()->t($key, $params);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Core\Controller;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Core\Csv;
use App\Modules\Admin\Services\TermsService;
use App\Modules\Admin\Services\PoliciesService;
use App\Modules\OrgStructure\Services\OrgService;

/**
 * Policies management controller.
 *
 * Multiple named policies, each with its own versions, audience scope,
 * activation state, and acknowledgement tracking. Routes are kept under
 * /admin/terms for backwards compatibility with existing links.
 */
class TermsController extends Controller
{
    private TermsService $termsService;
    private PoliciesService $policiesService;
    private OrgService $orgService;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->termsService = new TermsService($app->getDb());
        $this->policiesService = new PoliciesService($app->getDb());
        $this->orgService = new OrgService($app->getDb());
    }

    // ──── Policies list ────

    public function index(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.terms');
        if ($guard !== null) {
            return $guard;
        }

        $policies = $this->policiesService->getAll();
        foreach ($policies as &$p) {
            $p['stats'] = $this->policiesService->getStats((int) $p['id']);
        }
        unset($p);

        return $this->render('@admin/admin/terms/index.html.twig', [
            'policies' => $policies,
            'breadcrumbs' => [
                ['label' => $this->t('nav.terms')],
            ],
        ]);
    }

    /**
     * GET /admin/terms/create — convenience alias that routes to the version
     * create form for the first policy (or auto-creates a default policy if none).
     */
    public function createAlias(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.terms');
        if ($guard !== null) {
            return $guard;
        }

        $policies = $this->policiesService->getAll();
        if (empty($policies)) {
            $userId = (int) $this->app->getSession()->get('user')['id'];
            $policyId = $this->policiesService->createPolicy(
                $this->t('policies.default_name'),
                null,
                $userId,
                []
            );
        } else {
            $policyId = (int) $policies[0]['id'];
        }
        return $this->createVersionForm($request, ['id' => (string) $policyId]);
    }

    /**
     * POST /admin/terms — alias for storing a new version against the default policy.
     */
    public function storeAlias(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.terms');
        if ($guard !== null) {
            return $guard;
        }

        $policies = $this->policiesService->getAll();
        if (empty($policies)) {
            $userId = (int) $this->app->getSession()->get('user')['id'];
            $policyId = $this->policiesService->createPolicy(
                $this->t('policies.default_name'),
                null,
                $userId,
                []
            );
        } else {
            $policyId = (int) $policies[0]['id'];
        }
        return $this->storeVersion($request, ['id' => (string) $policyId]);
    }

    // ──── Policy CRUD ────

    public function createPolicyForm(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.terms');
        if ($guard !== null) {
            return $guard;
        }

        return $this->render('@admin/admin/terms/policy_form.html.twig', [
            'policy' => null,
            'scope' => [],
            'nodes' => $this->orgService->getTree(),
            'breadcrumbs' => [
                ['label' => $this->t('nav.terms'), 'url' => '/admin/terms'],
                ['label' => $this->t('policies.create')],
            ],
        ]);
    }

    public function storePolicy(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.terms');
        if ($guard !== null) {
            return $guard;
        }
        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) {
            return $csrfCheck;
        }

        $userId = (int) $this->app->getSession()->get('user')['id'];
        $name = (string) $request->getParam('name', '');
        $description = (string) $request->getParam('description', '');
        $nodeIds = $this->parseNodeIds($request->getParam('node_ids', []));

        if (trim($name) === '') {
            $this->flash('error', $this->t('policies.name_required'));
            return $this->redirect('/admin/terms/policies/create');
        }

        $id = $this->policiesService->createPolicy($name, $description ?: null, $userId, $nodeIds);
        $this->flash('success', $this->t('flash.saved'));
        return $this->redirect("/admin/terms/policies/{$id}");
    }

    public function showPolicy(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.terms');
        if ($guard !== null) {
            return $guard;
        }

        $id = (int) $vars['id'];
        $policy = $this->policiesService->getById($id);
        if (!$policy) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        $stats = $this->policiesService->getStats($id);
        $report = $this->policiesService->getAcknowledgementReport($id);
        $versions = $this->termsService->getVersionsByPolicy($id);
        $scopeIds = $this->policiesService->getScope($id);
        $scopeNodes = [];
        if (!empty($scopeIds)) {
            $placeholders = implode(',', array_fill(0, count($scopeIds), '?'));
            $scopeNodes = $this->app->getDb()->fetchAll(
                "SELECT id, name FROM org_nodes WHERE id IN ($placeholders)",
                array_values($scopeIds)
            );
        }

        return $this->render('@admin/admin/terms/policy_show.html.twig', [
            'policy' => $policy,
            'stats' => $stats,
            'report' => $report,
            'versions' => $versions,
            'scope_nodes' => $scopeNodes,
            'breadcrumbs' => [
                ['label' => $this->t('nav.terms'), 'url' => '/admin/terms'],
                ['label' => $policy['name']],
            ],
        ]);
    }

    public function editPolicyForm(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.terms');
        if ($guard !== null) {
            return $guard;
        }

        $id = (int) $vars['id'];
        $policy = $this->policiesService->getById($id);
        if (!$policy) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        return $this->render('@admin/admin/terms/policy_form.html.twig', [
            'policy' => $policy,
            'scope' => $this->policiesService->getScope($id),
            'nodes' => $this->orgService->getTree(),
            'breadcrumbs' => [
                ['label' => $this->t('nav.terms'), 'url' => '/admin/terms'],
                ['label' => $policy['name'], 'url' => "/admin/terms/policies/{$id}"],
                ['label' => $this->t('common.edit')],
            ],
        ]);
    }

    public function updatePolicy(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.terms');
        if ($guard !== null) {
            return $guard;
        }
        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) {
            return $csrfCheck;
        }

        $id = (int) $vars['id'];
        $name = (string) $request->getParam('name', '');
        $description = (string) $request->getParam('description', '');
        $nodeIds = $this->parseNodeIds($request->getParam('node_ids', []));

        if (trim($name) === '') {
            $this->flash('error', $this->t('policies.name_required'));
            return $this->redirect("/admin/terms/policies/{$id}/edit");
        }

        $this->policiesService->updatePolicy($id, $name, $description ?: null, $nodeIds);
        $this->flash('success', $this->t('flash.saved'));
        return $this->redirect("/admin/terms/policies/{$id}");
    }

    public function togglePolicyActive(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.terms');
        if ($guard !== null) {
            return $guard;
        }
        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) {
            return $csrfCheck;
        }

        $id = (int) $vars['id'];
        $policy = $this->policiesService->getById($id);
        if (!$policy) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        $this->policiesService->setActive($id, !((int) $policy['is_active']));
        $this->flash('success', $this->t('flash.saved'));
        return $this->redirect("/admin/terms/policies/{$id}");
    }

    public function exportCsv(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.terms');
        if ($guard !== null) {
            return $guard;
        }

        $id = (int) $vars['id'];
        $policy = $this->policiesService->getById($id);
        if (!$policy) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        $report = $this->policiesService->getAcknowledgementReport($id);

        $fh = fopen('php://temp', 'w+');
        Csv::put($fh, ['Membership #', 'First Name', 'Surname', 'Email', 'Acknowledged', 'Accepted At']);
        foreach ($report as $row) {
            Csv::put($fh, [
                $row['membership_number'],
                $row['first_name'],
                $row['surname'],
                $row['email'] ?? '',
                ((int) $row['acknowledged']) === 1 ? 'Yes' : 'No',
                $row['accepted_at'] ?? '',
            ]);
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        $filename = 'policy-' . $id . '-acknowledgements-' . date('Ymd-His') . '.csv';
        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    // ──── Version CRUD (scoped to a policy) ────

    public function createVersionForm(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.terms');
        if ($guard !== null) {
            return $guard;
        }

        $policyId = (int) $vars['id'];
        $policy = $this->policiesService->getById($policyId);
        if (!$policy) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        return $this->render('@admin/admin/terms/form.html.twig', [
            'version' => null,
            'policy' => $policy,
            'breadcrumbs' => [
                ['label' => $this->t('nav.terms'), 'url' => '/admin/terms'],
                ['label' => $policy['name'], 'url' => "/admin/terms/policies/{$policyId}"],
                ['label' => $this->t('terms.create')],
            ],
        ]);
    }

    public function storeVersion(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.terms');
        if ($guard !== null) {
            return $guard;
        }
        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) {
            return $csrfCheck;
        }

        $policyId = (int) $vars['id'];
        $userId = (int) $this->app->getSession()->get('user')['id'];

        $data = [
            'policy_id' => $policyId,
            'title' => trim((string) $request->getParam('title', '')),
            'content' => (string) $request->getParam('content', ''),
            'version_number' => trim((string) $request->getParam('version_number', '')),
            'grace_period_days' => (int) $request->getParam('grace_period_days', 14),
        ];

        $err = $this->validateVersion($data);
        if ($err !== null) {
            $this->flash('error', $err);
            return $this->redirect("/admin/terms/policies/{$policyId}/versions/create");
        }

        $this->termsService->createVersion($data, $userId);
        $this->flash('success', $this->t('flash.saved'));
        return $this->redirect("/admin/terms/policies/{$policyId}");
    }

    public function editVersionForm(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.terms');
        if ($guard !== null) {
            return $guard;
        }

        $id = (int) $vars['id'];
        $version = $this->termsService->getVersionById($id);
        if (!$version) {
            return $this->render('errors/404.html.twig', [], 404);
        }
        $policy = $this->policiesService->getById((int) $version['policy_id']);

        return $this->render('@admin/admin/terms/form.html.twig', [
            'version' => $version,
            'policy' => $policy,
            'breadcrumbs' => [
                ['label' => $this->t('nav.terms'), 'url' => '/admin/terms'],
                ['label' => $policy['name'], 'url' => '/admin/terms/policies/' . $policy['id']],
                ['label' => $this->t('common.edit')],
            ],
        ]);
    }

    public function updateVersion(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.terms');
        if ($guard !== null) {
            return $guard;
        }
        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) {
            return $csrfCheck;
        }

        $id = (int) $vars['id'];
        $version = $this->termsService->getVersionById($id);
        if (!$version) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        $data = [
            'title' => trim((string) $request->getParam('title', '')),
            'content' => (string) $request->getParam('content', ''),
            'version_number' => trim((string) $request->getParam('version_number', '')),
            'grace_period_days' => (int) $request->getParam('grace_period_days', 14),
        ];

        $err = $this->validateVersion($data + ['policy_id' => (int) $version['policy_id']]);
        if ($err !== null) {
            $this->flash('error', $err);
            return $this->redirect("/admin/terms/versions/{$id}/edit");
        }

        $this->termsService->updateVersion($id, $data);
        $this->flash('success', $this->t('flash.saved'));
        return $this->redirect('/admin/terms/policies/' . (int) $version['policy_id']);
    }

    public function publishVersion(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.terms');
        if ($guard !== null) {
            return $guard;
        }
        $csrfCheck = $this->validateCsrf($request);
        if ($csrfCheck !== null) {
            return $csrfCheck;
        }

        $id = (int) $vars['id'];
        $version = $this->termsService->getVersionById($id);
        if (!$version) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        $this->termsService->publishVersion($id);
        $this->flash('success', $this->t('terms.published'));
        return $this->redirect('/admin/terms/policies/' . (int) $version['policy_id']);
    }

    public function showVersion(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('admin.terms');
        if ($guard !== null) {
            return $guard;
        }

        $id = (int) $vars['id'];
        $version = $this->termsService->getVersionById($id);
        if (!$version) {
            return $this->render('errors/404.html.twig', [], 404);
        }
        $policy = $this->policiesService->getById((int) $version['policy_id']);
        $acceptances = $this->termsService->getAcceptanceReport($id);

        return $this->render('@admin/admin/terms/show.html.twig', [
            'version' => $version,
            'policy' => $policy,
            'acceptances' => $acceptances,
            'breadcrumbs' => [
                ['label' => $this->t('nav.terms'), 'url' => '/admin/terms'],
                ['label' => $policy['name'], 'url' => '/admin/terms/policies/' . $policy['id']],
                ['label' => $version['title']],
            ],
        ]);
    }

    // ──── Helpers ────

    private function validateVersion(array $data): ?string
    {
        if (empty($data['title'])) {
            return $this->t('terms.title_required');
        }
        if (empty(trim($data['content']))) {
            return $this->t('terms.content_required');
        }
        if (empty($data['version_number'])) {
            return $this->t('terms.version_required');
        }
        return null;
    }

    private function parseNodeIds($raw): array
    {
        if (!is_array($raw)) {
            $raw = $raw ? [$raw] : [];
        }
        return array_values(array_filter(array_map('intval', $raw), fn($n) => $n > 0));
    }

    private function t(string $key, array $params = []): string
    {
        return $this->app->getI18n()->t($key, $params);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Members\Controllers;

use App\Core\Controller;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Members\Services\MemberService;
use App\Modules\Members\Services\CustomFieldService;
use App\Modules\OrgStructure\Services\OrgService;
use App\Modules\Admin\Services\PoliciesService;

/**
 * Members management controller.
 *
 * Handles member listing (paginated, filterable, permission-scoped),
 * view, create, edit, status changes, search, and pending change review.
 */
class MembersController extends Controller
{
    private MemberService $memberService;
    private CustomFieldService $customFieldService;
    private OrgService $orgService;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $encryption = $this->getEncryption();
        $this->memberService = new MemberService($app->getDb(), $encryption);
        $this->customFieldService = new CustomFieldService($app->getDb());
        $this->orgService = new OrgService($app->getDb());
    }

    /**
     * GET /members — paginated member list.
     */
    public function index(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('members.read');
        if ($guard !== null) {
            return $guard;
        }

        $page = max(1, (int) $request->getParam('page', 1));
        $status = $request->getParam('status', '');
        $nodeId = $request->getParam('node_id', '');
        $query = trim((string) $request->getParam('q', ''));

        $ctx = $this->resolveViewContext();

        $filters = ['query' => $query];
        if ($status !== '') {
            $filters['status'] = $status;
        }
        if ($nodeId !== '') {
            $filters['node_id'] = (int) $nodeId;
        }

        $result = $this->memberService->listScoped($ctx, $filters, $page, 25);

        // If the scoped list is empty, check whether a broader scope would
        // produce results — this drives the "switch to All nodes" empty-state
        // action (Q28 in the plan's decision log).
        $hasResultsInBroaderScope = false;
        if ($result['total'] === 0 && !$ctx->isAllNodes() && count($ctx->availableScopes) > 1) {
            $broader = $this->memberService->listScoped(
                new \App\Core\ViewContext(
                    $ctx->mode,
                    null,
                    $ctx->availableScopes,
                    $ctx->canSwitchToAdmin,
                    $ctx->canSwitchToMember,
                    $ctx->scopeAppliesToCurrentPage,
                ),
                $filters,
                1,
                1,
            );
            $hasResultsInBroaderScope = $broader['total'] > 0;
        }

        $statusCounts = $this->memberService->getStatusCounts(
            $this->memberService->expandNodeSubtree($ctx->scopeNodeIds())
        );
        $nodes = $this->orgService->getTree();

        return $this->render('@members/members/index.html.twig', [
            'members' => $result['items'],
            'pagination' => [
                'page' => $result['page'],
                'pages' => $result['pages'],
                'total' => $result['total'],
                'per_page' => $result['per_page'],
            ],
            'filters' => [
                'q' => $query,
                'status' => $status,
                'node_id' => $nodeId,
            ],
            'status_counts' => $statusCounts,
            'nodes' => $nodes,
            'has_results_in_broader_scope' => $hasResultsInBroaderScope,
            'breadcrumbs' => [
                ['label' => $this->t('nav.members')],
            ],
        ]);
    }

    /**
     * GET /members/{id} — view a member.
     */
    public function view(Request $request, array $vars): Response
    {
        $authCheck = $this->requireAuth();
        if ($authCheck !== null) {
            return $authCheck;
        }

        $memberId = (int) $vars['id'];
        $member = $this->memberService->getById($memberId);

        if ($member === null) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        $sessionUser = $this->app->getSession()->get('user') ?? [];
        $isOwnRecord = !empty($sessionUser['id'])
            && (int) ($member['user_id'] ?? 0) === (int) $sessionUser['id'];

        if ($this->app->getPermissionResolver()->can('members.read')) {
            // Scope access: plan Q15 / Q30. If the viewer is in admin mode and
            // the record falls outside their active scope, check whether it's
            // their own (or a family-linked) record — silently redirect into
            // member mode so they can still see their profile — otherwise
            // render a friendly scope-error page.
            $ctx = $this->resolveViewContext();
            if ($ctx->isAdmin() && !$this->memberService->isMemberInScope($memberId, $ctx)) {
                if ($isOwnRecord) {
                    return $this->redirect('/me?mode=member');
                }
                return $this->render('errors/403.html.twig', [], 403);
            }

            if (!$this->canAccessMember($member)) {
                return $this->render('errors/403.html.twig', [], 403);
            }
        } elseif (!$isOwnRecord) {
            // Without members.read a user may only view their own record
            // (reached via /account and /me/profile)
            return $this->render('errors/403.html.twig', [], 403);
        }

        // Count outstanding policies only when viewing one's own profile
        $outstandingPolicies = 0;
        $user = $this->app->getSession()->get('user');
        $sessionMemberId = (int) ($user['member_id'] ?? 0);
        if ($sessionMemberId === 0 && !empty($user['id'])) {
            $row = $this->app->getDb()->fetchOne(
                "SELECT id FROM members WHERE user_id = :uid LIMIT 1",
                ['uid' => (int) $user['id']]
            );
            $sessionMemberId = $row ? (int) $row['id'] : 0;
        }
        if ($sessionMemberId === $memberId && $sessionMemberId > 0) {
            $policiesService = new PoliciesService($this->app->getDb());
            $outstandingPolicies = count($policiesService->getOutstandingForMember($memberId));
        }

        return $this->render('@members/members/view.html.twig', [
            'member' => $member,
            'outstanding_policies' => $outstandingPolicies,
            'breadcrumbs' => [
                ['label' => $this->t('nav.members'), 'url' => '/members'],
                ['label' => $member['first_name'] . ' ' . $member['surname']],
            ],
        ]);
    }

    /**
     * GET /members/create — show create form.
     */
    public function create(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('members.write');
        if ($guard !== null) {
            return $guard;
        }

        $nodes = $this->orgService->getTree();

        return $this->render('@members/members/form.html.twig', [
            'member' => null,
            'nodes' => $nodes,
            'custom_fields' => $this->customFieldService->getRenderableFields(),
            'breadcrumbs' => [
                ['label' => $this->t('nav.members'), 'url' => '/members'],
                ['label' => $this->t('members.add_member')],
            ],
        ]);
    }

    /**
     * POST /members — store a new member.
     */
    public function store(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('members.write');
        if ($guard !== null) {
            return $guard;
        }

        $data = $this->extractMemberData($request);

        // Validate required
        if (empty($data['first_name'])) {
            $this->flash('error', $this->t('members.first_name_required'));
            return $this->redirect('/members/create');
        }
        if (empty($data['surname'])) {
            $this->flash('error', $this->t('members.surname_required'));
            return $this->redirect('/members/create');
        }

        try {
            $memberId = $this->memberService->create($data);
            $this->flash('success', $this->t('flash.saved'));
            return $this->redirect("/members/$memberId");
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect('/members/create');
        }
    }

    /**
     * GET /members/{id}/edit — show edit form.
     */
    public function edit(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('members.write');
        if ($guard !== null) {
            return $guard;
        }

        $memberId = (int) $vars['id'];
        $member = $this->memberService->getById($memberId);

        if ($member === null) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        if (!$this->canAccessMember($member)) {
            return $this->render('errors/403.html.twig', [], 403);
        }

        $nodes = $this->orgService->getTree();

        $customData = $member['member_custom_data'] ?? [];
        return $this->render('@members/members/form.html.twig', [
            'member' => $member,
            'nodes' => $nodes,
            'custom_fields' => $this->customFieldService->getRenderableFields($customData),
            'breadcrumbs' => [
                ['label' => $this->t('nav.members'), 'url' => '/members'],
                ['label' => $member['first_name'] . ' ' . $member['surname'], 'url' => "/members/$memberId"],
                ['label' => $this->t('common.edit')],
            ],
        ]);
    }

    /**
     * POST /members/{id} — update a member.
     */
    public function update(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('members.write');
        if ($guard !== null) {
            return $guard;
        }

        $memberId = (int) $vars['id'];
        $member = $this->memberService->getById($memberId);

        if ($member === null) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        if (!$this->canAccessMember($member)) {
            return $this->render('errors/403.html.twig', [], 403);
        }

        $data = $this->extractMemberData($request);

        if (empty($data['first_name'])) {
            $this->flash('error', $this->t('members.first_name_required'));
            return $this->redirect("/members/$memberId/edit");
        }
        if (empty($data['surname'])) {
            $this->flash('error', $this->t('members.surname_required'));
            return $this->redirect("/members/$memberId/edit");
        }

        $this->memberService->update($memberId, $data);
        $this->flash('success', $this->t('flash.saved'));
        return $this->redirect("/members/$memberId");
    }

    /**
     * POST /members/{id}/status — change a member's status.
     */
    public function changeStatus(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('members.write');
        if ($guard !== null) {
            return $guard;
        }

        $memberId = (int) $vars['id'];
        $status = trim((string) $this->getParam('status', ''));
        $reason = trim((string) $this->getParam('status_reason', ''));

        try {
            $this->memberService->changeStatus($memberId, $status, $reason ?: null);
            $this->flash('success', $this->t('flash.saved'));
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect("/members/$memberId");
    }

    /**
     * POST /members/{id}/create-account — create a login account for a member who has none.
     */
    public function createAccount(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('roles.write');
        if ($guard !== null) {
            return $guard;
        }

        $csrfGuard = $this->validateCsrf($request);
        if ($csrfGuard !== null) {
            return $csrfGuard;
        }

        $memberId = (int) $vars['id'];
        $member = $this->memberService->getById($memberId);

        if ($member === null) {
            return $this->render('errors/404.html.twig', [], 404);
        }

        $email = trim((string) $this->getParam('email', $member['email'] ?? ''));
        $password = (string) $this->getParam('password', '');

        try {
            $userId = $this->memberService->createUserAccount($memberId, $email, $password);
            $this->flash('success', $this->t('members.account_created'));
            return $this->redirect("/admin/roles/assignments/$userId");
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
            return $this->redirect("/members/$memberId");
        }
    }

    /**
     * GET /members/pending-changes — list pending changes.
     */
    public function pendingChanges(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('members.write');
        if ($guard !== null) {
            return $guard;
        }

        $ctx = $this->resolveViewContext();
        $scopeNodeIds = $this->memberService->expandNodeSubtree($ctx->scopeNodeIds());
        $changes = $this->memberService->getPendingChanges(null, $scopeNodeIds);

        return $this->render('@members/members/pending_changes.html.twig', [
            'changes' => $changes,
            'breadcrumbs' => [
                ['label' => $this->t('nav.members'), 'url' => '/members'],
                ['label' => $this->t('members.pending_changes')],
            ],
        ]);
    }

    /**
     * POST /members/pending-changes/{id}/review — approve or reject a pending change.
     */
    public function reviewChange(Request $request, array $vars): Response
    {
        $guard = $this->requirePermission('members.write');
        if ($guard !== null) {
            return $guard;
        }

        $changeId = (int) $vars['id'];
        $decision = trim((string) $this->getParam('decision', ''));
        $userId = (int) $this->app->getSession()->get('user')['id'];

        try {
            $this->memberService->reviewChange($changeId, $decision, $userId);
            $this->flash('success', $this->t('flash.saved'));
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect('/members/pending-changes');
    }

    // ──── Private helpers ────

    /**
     * Extract member data from the request.
     */
    private function extractMemberData(Request $request): array
    {
        $nodeIds = $request->getParam('node_ids', []);
        if (!is_array($nodeIds)) {
            $nodeIds = $nodeIds ? [(int) $nodeIds] : [];
        }

        // Custom fields — sanitise against active definitions
        $customData = $request->getParam('custom_data', []);
        if (is_array($customData) && !empty($customData)) {
            $customData = $this->customFieldService->sanitiseCustomData($customData);
        } else {
            $customData = null;
        }

        return [
            'first_name' => trim((string) $this->getParam('first_name', '')),
            'surname' => trim((string) $this->getParam('surname', '')),
            'dob' => $this->getParam('dob') ?: null,
            'gender' => $this->getParam('gender') ?: null,
            'email' => $this->getParam('email') ?: null,
            'phone' => $this->getParam('phone') ?: null,
            'address_line1' => $this->getParam('address_line1') ?: null,
            'address_line2' => $this->getParam('address_line2') ?: null,
            'city' => $this->getParam('city') ?: null,
            'postcode' => $this->getParam('postcode') ?: null,
            'country' => $this->getParam('country') ?: 'Malta',
            'medical_notes' => $this->getParam('medical_notes') ?: null,
            'status' => $this->getParam('status') ?: 'pending',
            'status_reason' => $this->getParam('status_reason') ?: null,
            'joined_date' => $this->getParam('joined_date') ?: null,
            'gdpr_consent' => (int) $this->getParam('gdpr_consent', 0),
            'node_ids' => array_map('intval', $nodeIds),
            'primary_node_id' => $this->getParam('primary_node_id') ? (int) $this->getParam('primary_node_id') : null,
            'member_custom_data' => $customData,
        ];
    }

    /**
     * Get the scope node IDs for the current user (empty = unrestricted).
     */
    private function getScopeNodeIds(): array
    {
        $resolver = $this->app->getPermissionResolver();
        return $resolver->getScopeNodeIds();
    }

    /**
     * Check if the current user can access a member based on scope.
     */
    private function canAccessMember(array $member): bool
    {
        $resolver = $this->app->getPermissionResolver();
        if ($resolver->isSuperAdmin()) {
            return true;
        }

        $scopeNodeIds = $resolver->getScopeNodeIds();
        if (empty($scopeNodeIds)) {
            return false;
        }

        $memberNodeIds = $member['node_ids'] ?? [];
        if (empty($memberNodeIds)) {
            // Members without node assignments — accessible only to admins with global scope
            return false;
        }

        // Check if any of the member's nodes overlap with the user's scope
        return !empty(array_intersect($memberNodeIds, $scopeNodeIds));
    }

    /**
     * Get encryption instance if available.
     */
    private function getEncryption(): ?\App\Core\Encryption
    {
        $keyFile = $this->app->getConfigValue('security.encryption_key_file', '');
        if ($keyFile === '') {
            return null;
        }

        try {
            return new \App\Core\Encryption($keyFile);
        } catch (\RuntimeException $e) {
            return null;
        }
    }

    /**
     * Translate a key.
     */
    private function t(string $key, array $params = []): string
    {
        return $this->app->getI18n()->t($key, $params);
    }
}

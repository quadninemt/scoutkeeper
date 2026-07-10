<?php

declare(strict_types=1);

namespace App\Modules\Members\Controllers;

use App\Core\Controller;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Members\Services\MemberService;
use App\Modules\Members\Services\CustomFieldService;
use App\Modules\Members\Services\TimelineService;
use App\Modules\Members\Services\AttachmentService;

/**
 * Member profile tab controller.
 *
 * Returns HTMX partials for each lazy-loaded tab on the member view page.
 * Medical tab enforces can_access_medical permission and logs access.
 */
class MemberTabsController extends Controller
{
    private MemberService $memberService;
    private CustomFieldService $customFieldService;
    private TimelineService $timelineService;
    private AttachmentService $attachmentService;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $encryption = $this->getEncryption();
        $this->memberService = new MemberService($app->getDb(), $encryption);
        $this->customFieldService = new CustomFieldService($app->getDb());
        $this->timelineService = new TimelineService($app->getDb());
        $uploadPath = $app->getConfigValue('app.data_path', ROOT_PATH . '/data') . '/uploads';
        $this->attachmentService = new AttachmentService($app->getDb(), $uploadPath);
    }

    /**
     * GET /members/{id}/tab/personal — personal details tab.
     */
    public function personal(Request $request, array $vars): Response
    {
        $guard = $this->guardMemberTab((int) $vars['id']);
        if ($guard !== null) {
            return $guard;
        }

        $member = $this->getMemberOrFail((int) $vars['id']);
        if ($member instanceof Response) {
            return $member;
        }

        return $this->render('@members/partials/tabs/_personal.html.twig', [
            'member' => $member,
        ]);
    }

    /**
     * GET /members/{id}/tab/contact — contact details tab.
     */
    public function contact(Request $request, array $vars): Response
    {
        $guard = $this->guardMemberTab((int) $vars['id']);
        if ($guard !== null) {
            return $guard;
        }

        $member = $this->getMemberOrFail((int) $vars['id']);
        if ($member instanceof Response) {
            return $member;
        }

        return $this->render('@members/partials/tabs/_contact.html.twig', [
            'member' => $member,
        ]);
    }

    /**
     * GET /members/{id}/tab/medical — medical notes tab.
     *
     * Enforces can_access_medical flag. Access is logged.
     */
    public function medical(Request $request, array $vars): Response
    {
        $guard = $this->guardMemberTab((int) $vars['id']);
        if ($guard !== null) {
            return $guard;
        }

        $memberId = (int) $vars['id'];
        $resolver = $this->app->getPermissionResolver();
        // Members may always see their own medical notes (they can edit them)
        $hasAccess = $resolver->canAccessMedical() || $this->isOwnRecord($memberId);

        $medicalNotes = null;
        if ($hasAccess) {
            // Fetch member with decrypted medical notes + log access
            $user = $this->app->getSession()->get('user');
            $userId = $user ? (int) $user['id'] : 0;
            $ip = $request->getClientIp();
            $member = $this->memberService->getById($memberId, true, $userId, $ip);
            $medicalNotes = $member['medical_notes'] ?? null;
        }

        return $this->render('@members/partials/tabs/_medical.html.twig', [
            'member' => ['id' => $memberId],
            'has_access' => $hasAccess,
            'medical_notes' => $medicalNotes,
        ]);
    }

    /**
     * GET /members/{id}/tab/roles — role history tab.
     */
    public function roles(Request $request, array $vars): Response
    {
        $guard = $this->guardMemberTab((int) $vars['id']);
        if ($guard !== null) {
            return $guard;
        }

        $memberId = (int) $vars['id'];

        // Get role assignments for member's user_id
        $member = $this->memberService->getById($memberId);
        if (!$member) {
            return Response::html('<p class="text-body-secondary">Member not found.</p>', 404);
        }

        $assignments = [];
        if (!empty($member['user_id'])) {
            // Load role assignments — context uses context_type + context_id
            $assignments = $this->app->getDb()->fetchAll(
                "SELECT ra.*, r.name AS role_name,
                        CASE WHEN ra.end_date IS NULL OR ra.end_date > CURDATE() THEN 1 ELSE 0 END AS is_active,
                        CASE
                            WHEN ra.context_type = 'node' THEN (SELECT name FROM `org_nodes` WHERE id = ra.context_id)
                            WHEN ra.context_type = 'team' THEN (SELECT name FROM `org_teams` WHERE id = ra.context_id)
                            ELSE 'Global'
                        END AS context_name
                 FROM `role_assignments` ra
                 JOIN `roles` r ON r.id = ra.role_id
                 WHERE ra.user_id = ?
                 ORDER BY ra.start_date DESC",
                [$member['user_id']]
            );
        }

        $canWriteRoles = $this->app->getPermissionResolver()->can('roles.write');

        return $this->render('@members/partials/tabs/_roles.html.twig', [
            'member' => $member,
            'assignments' => $assignments,
            'can_write_roles' => $canWriteRoles,
        ]);
    }

    /**
     * GET /members/{id}/tab/timeline — timeline/history tab.
     */
    public function timeline(Request $request, array $vars): Response
    {
        $guard = $this->guardMemberTab((int) $vars['id']);
        if ($guard !== null) {
            return $guard;
        }

        $memberId = (int) $vars['id'];
        $canWrite = $this->app->getPermissionResolver()->can('members.write');
        $groupedEntries = $this->timelineService->getEntriesGrouped($memberId);

        return $this->render('@members/partials/tabs/_timeline.html.twig', [
            'grouped_entries' => $groupedEntries,
            'member_id' => $memberId,
            'can_write' => $canWrite,
        ]);
    }

    /**
     * GET /members/{id}/tab/documents — documents/attachments tab.
     */
    public function documents(Request $request, array $vars): Response
    {
        $guard = $this->guardMemberTab((int) $vars['id']);
        if ($guard !== null) {
            return $guard;
        }

        $memberId = (int) $vars['id'];
        $canWrite = $this->app->getPermissionResolver()->can('members.write');
        $attachments = $this->attachmentService->getForMember($memberId);

        return $this->render('@members/partials/tabs/_documents.html.twig', [
            'attachments' => $attachments,
            'member_id' => $memberId,
            'can_write' => $canWrite,
        ]);
    }

    /**
     * GET /members/{id}/tab/additional — custom fields tab.
     */
    public function additional(Request $request, array $vars): Response
    {
        $guard = $this->guardMemberTab((int) $vars['id']);
        if ($guard !== null) {
            return $guard;
        }

        $memberId = (int) $vars['id'];
        $member = $this->memberService->getById($memberId);
        if (!$member) {
            return Response::html('<p class="text-body-secondary">Member not found.</p>', 404);
        }

        $customData = $member['member_custom_data'] ?? [];
        $customFields = $this->customFieldService->getRenderableFields($customData);

        return $this->render('@members/partials/tabs/_additional.html.twig', [
            'custom_fields' => $customFields,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /**
     * Guard a member profile tab: members.read grants access to any record;
     * without it, a member may only load tabs of their own record (mirrors
     * MembersController::view).
     */
    private function guardMemberTab(int $memberId): ?Response
    {
        $authCheck = $this->requireAuth();
        if ($authCheck !== null) {
            return $authCheck;
        }

        if ($this->app->getPermissionResolver()->can('members.read')) {
            return null;
        }

        if ($this->isOwnRecord($memberId)) {
            return null;
        }

        return $this->render('errors/403.html.twig', [], 403);
    }

    /**
     * Whether the given member record belongs to the logged-in user.
     */
    private function isOwnRecord(int $memberId): bool
    {
        $user = $this->app->getSession()->get('user') ?? [];
        if (empty($user['id'])) {
            return false;
        }

        $row = $this->app->getDb()->fetchOne(
            "SELECT id FROM members WHERE id = :id AND user_id = :uid LIMIT 1",
            ['id' => $memberId, 'uid' => (int) $user['id']]
        );

        return $row !== null && $row !== false;
    }

    /**
     * Load member or return 404 response.
     */
    private function getMemberOrFail(int $id): array|Response
    {
        $member = $this->memberService->getById($id);
        if (!$member) {
            return Response::html('<p class="text-body-secondary">Member not found.</p>', 404);
        }
        return $member;
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
}

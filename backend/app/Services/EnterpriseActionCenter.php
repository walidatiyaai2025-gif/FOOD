<?php

namespace App\Services;

use App\Models\EnterpriseWorkflowStep;
use App\Models\Role;
use App\Models\User;
use App\Models\UserStoreRole;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class EnterpriseActionCenter
{
    public const PAGE_SIZE = 25;

    public const MAX_CANDIDATES = 200;

    /**
     * @return array{actions:LengthAwarePaginator<int, array<string, mixed>>,truncated:bool}
     */
    public function page(
        User $actor,
        string $search,
        string $state,
        int $page,
        string $path,
    ): array {
        $search = trim(Str::limit($search, 100, ''));

        $query = EnterpriseWorkflowStep::query()
            ->with(['workflowRequest.requester:id,name'])
            ->where('status', 'PENDING')
            ->whereHas('workflowRequest', fn ($request) => $request->where('status', 'PENDING'));

        if ($search !== '') {
            $query->where(function ($candidate) use ($search): void {
                $like = '%'.$search.'%';

                $candidate
                    ->where('step_code', 'like', $like)
                    ->orWhereHas('workflowRequest', function ($request) use ($like): void {
                        $request
                            ->where('workflow_key', 'like', $like)
                            ->orWhere('entity_type', 'like', $like)
                            ->orWhere('entity_id', 'like', $like);
                    });
            });
        }

        $candidates = $query
            ->orderBy('workflow_request_id')
            ->orderBy('sequence')
            ->limit(self::MAX_CANDIDATES + 1)
            ->get();

        $truncated = $candidates->count() > self::MAX_CANDIDATES;
        $candidates = $candidates->take(self::MAX_CANDIDATES)->values();

        $authorization = $this->authorizationSnapshot($actor, $candidates->all());

        $visible = [];
        foreach ($candidates as $step) {
            $workflow = $step->workflowRequest;
            if ($workflow === null || ! $this->canDisplay($actor, $workflow, $step, $authorization)) {
                continue;
            }

            $context = is_array($workflow->context) ? $workflow->context : [];
            $dueAt = $this->dueAt($context['due_at'] ?? null);
            $overdue = $dueAt !== null && $dueAt->isPast();

            if ($state === 'overdue' && ! $overdue) {
                continue;
            }

            if ($state === 'pending' && $overdue) {
                continue;
            }

            $visible[] = [
                'workflow_request_id' => (int) $workflow->getKey(),
                'step_code' => (string) $step->step_code,
                'title' => $this->safeLabel($context['action_title'] ?? null),
                'record_label' => $this->safeLabel($context['record_label'] ?? null),
                'deep_link' => $this->safeAdminLink($context['deep_link'] ?? null),
                'requester_name' => $this->safeLabel($workflow->requester?->name),
                'created_at' => $workflow->created_at,
                'due_at' => $dueAt,
                'overdue' => $overdue,
            ];
        }

        $total = count($visible);
        $page = max(1, $page);
        $offset = ($page - 1) * self::PAGE_SIZE;
        $items = array_slice($visible, $offset, self::PAGE_SIZE);

        $paginator = new LengthAwarePaginator(
            $items,
            $total,
            self::PAGE_SIZE,
            $page,
            [
                'path' => $path,
                'pageName' => 'actions_page',
            ],
        );

        if ($search !== '') {
            $paginator->appends('q', $search);
        }
        if ($state !== 'all') {
            $paginator->appends('state', $state);
        }

        return ['actions' => $paginator, 'truncated' => $truncated];
    }

    /**
     * UI visibility is a bounded projection of the existing User permission model.
     * WorkflowEngine re-authorizes every mutation through WorkflowAuthorizer.
     *
     * @param  list<EnterpriseWorkflowStep>  $steps
     * @return array{
     *   super_admin:bool,
     *   global_permissions:array<string,true>,
     *   global_role_permissions:array<string,array<string,true>>,
     *   store_role_permissions:array<int,array<string,true>>,
     *   store_channels:array<int,string>
     * }
     */
    private function authorizationSnapshot(User $actor, array $steps): array
    {
        if ($actor->hasRole('SUPER_ADMIN')) {
            return [
                'super_admin' => true,
                'global_permissions' => [],
                'global_role_permissions' => [],
                'store_role_permissions' => [],
                'store_channels' => [],
            ];
        }

        $globalRoles = $actor->roles()
            ->where('roles.is_active', true)
            ->where('roles.scope', 'global')
            ->with('permissions:id,code')
            ->get();

        $globalPermissions = [];
        $globalRolePermissions = [];
        foreach ($globalRoles as $role) {
            $codes = [];
            foreach ($role->permissions as $permission) {
                $codes[(string) $permission->code] = true;
                $globalPermissions[(string) $permission->code] = true;
            }
            $globalRolePermissions[(string) $role->code] = $codes;
        }

        $storeIds = collect($steps)
            ->map(fn (EnterpriseWorkflowStep $step): ?int => $step->workflowRequest?->scope_type === 'store'
                && $step->workflowRequest?->scope_id !== null
                    ? (int) $step->workflowRequest->scope_id
                    : null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $storeChannels = $storeIds === []
            ? collect()
            : DB::table('stores')
                ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
                ->whereIn('stores.id', $storeIds)
                ->where('stores.is_active', true)
                ->pluck('store_types.code', 'stores.id');

        $storeRolePermissions = [];
        $assignments = $storeIds === []
            ? collect()
            : $actor->storeRoleAssignments()
                ->whereIn('store_id', $storeIds)
                ->with('role.permissions:id,code')
                ->get();

        foreach ($assignments as $assignment) {
            if (! $assignment instanceof UserStoreRole) {
                continue;
            }

            $role = $assignment->role;
            if (! $role instanceof Role || ! $role->is_active || $role->scope !== 'store') {
                continue;
            }

            $storeId = (int) $assignment->store_id;
            foreach ($role->permissions as $permission) {
                $storeRolePermissions[$storeId][(string) $permission->code] = true;
            }
        }

        return [
            'super_admin' => false,
            'global_permissions' => $globalPermissions,
            'global_role_permissions' => $globalRolePermissions,
            'store_role_permissions' => $storeRolePermissions,
            'store_channels' => $storeChannels
                ->mapWithKeys(fn ($channel, $storeId): array => [(int) $storeId => strtolower((string) $channel)])
                ->all(),
        ];
    }

    /** @param array<string, mixed> $authorization */
    private function canDisplay(
        User $actor,
        object $workflow,
        EnterpriseWorkflowStep $step,
        array $authorization,
    ): bool {
        if (! $actor->is_active) {
            return false;
        }

        if (! $step->allow_requester_decision && (int) $workflow->requester_id === (int) $actor->getKey()) {
            return false;
        }

        if ($authorization['super_admin']) {
            return true;
        }

        $permission = (string) $step->required_permission;
        if ($workflow->scope_type !== 'store') {
            return isset($authorization['global_permissions'][$permission]);
        }

        if ($workflow->scope_id === null) {
            return false;
        }

        $storeId = (int) $workflow->scope_id;
        $channel = $authorization['store_channels'][$storeId] ?? null;
        if ($channel === null) {
            return false;
        }

        if ($channel === 'b2c' && isset($authorization['store_role_permissions'][$storeId][$permission])) {
            return true;
        }

        $allowedGlobalRoles = array_values((array) config("admin.channels.{$channel}.global_roles", []));
        foreach ($allowedGlobalRoles as $roleCode) {
            if (isset($authorization['global_role_permissions'][$roleCode][$permission])) {
                return true;
            }
        }

        return false;
    }

    private function dueAt(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function safeLabel(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(strip_tags($value));
        if ($value === '') {
            return null;
        }

        return Str::limit($value, 160, '…');
    }

    private function safeAdminLink(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if (! str_starts_with($value, '/admin/')
            || str_starts_with($value, '//')
            || str_contains($value, '://')
            || str_contains($value, '\\')
            || str_contains($value, '..')
            || preg_match('/[\r\n]/', $value) === 1) {
            return null;
        }

        return Str::limit($value, 500, '');
    }
}

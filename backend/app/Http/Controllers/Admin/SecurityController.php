<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EnterpriseWorkflowRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\DemoDataManager;
use App\Services\EnterpriseSecurityAdministration;
use App\Services\RbacManager;
use Database\Seeders\ProductDemoSeeder;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SecurityController extends Controller
{
    public function index(Request $request, DemoDataManager $demoData, EnterpriseSecurityAdministration $enterpriseSecurityAdmin): View
    {
        Gate::authorize('security.view');
        $this->useActorLocale($request);

        $actor = $this->actor($request);
        $isSuperAdmin = $actor->hasRole('SUPER_ADMIN');
        if (! $isSuperAdmin) {
            $actor->loadMissing([
                'roles.permissions:id,code',
                'storeRoleAssignments.role.permissions:id,code',
            ]);
        }
        $search = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', 'all');

        if (! in_array($status, ['all', 'active', 'inactive'], true)) {
            $status = 'all';
        }

        $users = User::query()
            ->with(['roles:id,code,name,is_active', 'storeRoleAssignments.role:id,code,name,is_active', 'storeRoleAssignments.store:id,code,name'])
            ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search): void {
                $nested->where('name', 'like', '%'.$search.'%')
                    ->orWhere('username', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            }))
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        $roles = Role::query()
            ->with('permissions:id,code,name')
            ->withCount(['users', 'storeAssignments'])
            ->orderByDesc('is_system')
            ->orderBy('code')
            ->get();

        $stores = Store::query()
            ->select('stores.*')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2C')
            ->orderBy('stores.name')
            ->get(['stores.id', 'stores.code', 'stores.name']);

        $grantablePermissionCodes = $isSuperAdmin
            ? null
            : collect($actor->effectivePermissionCodes());

        $permissions = Permission::query()
            ->orderBy('code')
            ->get()
            ->when(! $isSuperAdmin, fn ($items) => $items
                ->filter(fn (Permission $permission): bool => $grantablePermissionCodes?->contains($permission->code) === true)
                ->values());

        $permissionGroups = $permissions->groupBy(static fn (Permission $permission): string => Str::before($permission->code, '.'));
        $groupTranslations = trans('security_permissions.groups');
        $actionTranslations = trans('security_permissions.actions');
        $groupTranslations = is_array($groupTranslations) ? $groupTranslations : [];
        $actionTranslations = is_array($actionTranslations) ? $actionTranslations : [];

        $permissionLabels = $permissions->mapWithKeys(function (Permission $permission) use ($groupTranslations, $actionTranslations): array {
            $modulePath = Str::beforeLast($permission->code, '.');
            $rootModule = Str::before($permission->code, '.');
            $action = Str::afterLast($permission->code, '.');
            $moduleLabel = $groupTranslations[$modulePath]
                ?? $groupTranslations[$rootModule]
                ?? Str::headline(str_replace('_', ' ', $modulePath));
            $actionLabel = $actionTranslations[$action] ?? Str::headline(str_replace('_', ' ', $action));

            return [(int) $permission->id => $moduleLabel.' — '.$actionLabel];
        });

        $permissionGroupLabels = $permissionGroups->keys()->mapWithKeys(
            fn (string $module): array => [
                $module => $groupTranslations[$module] ?? Str::headline(str_replace('_', ' ', $module)),
            ],
        )->all();

        $globalCandidates = $roles->where('scope', 'global')->where('is_active', true)->values();
        $storeCandidates = $roles->where('scope', 'store')->where('is_active', true)->values();

        $globalRoles = $isSuperAdmin
            ? $globalCandidates
            : $globalCandidates
                ->reject(fn (Role $role): bool => $role->code === 'SUPER_ADMIN')
                ->filter(fn (Role $role): bool => $role->permissions->pluck('code')->diff($grantablePermissionCodes ?? collect())->isEmpty())
                ->values();

        $allowedRetailGlobalRoles = array_values((array) config('admin.channels.b2c.global_roles', []));
        $retailGlobalPermissionCodes = $isSuperAdmin
            ? collect()
            : $actor->roles
                ->filter(fn (Role $role): bool => (bool) $role->is_active
                    && $role->scope === 'global'
                    && in_array($role->code, $allowedRetailGlobalRoles, true))
                ->flatMap(fn (Role $role) => $role->permissions->pluck('code'))
                ->unique()
                ->values();

        $retailStorePermissionCodes = $isSuperAdmin
            ? collect()
            : $actor->storeRoleAssignments
                ->filter(fn ($assignment): bool => (bool) $assignment->role->is_active
                    && $assignment->role->scope === 'store')
                ->groupBy('store_id')
                ->map(fn ($assignments) => $assignments
                    ->flatMap(fn ($assignment) => $assignment->role->permissions->pluck('code'))
                    ->unique()
                    ->values());

        $grantableStoreRolesByStore = $stores->mapWithKeys(function (Store $store) use (
            $isSuperAdmin,
            $storeCandidates,
            $retailGlobalPermissionCodes,
            $retailStorePermissionCodes,
        ): array {
            if ($isSuperAdmin) {
                return [(int) $store->id => $storeCandidates];
            }

            $effective = $retailGlobalPermissionCodes
                ->merge($retailStorePermissionCodes->get((int) $store->id, collect()))
                ->unique();

            return [
                (int) $store->id => $storeCandidates
                    ->filter(fn (Role $role): bool => $role->permissions->pluck('code')->diff($effective)->isEmpty())
                    ->values(),
            ];
        });

        $editableRoleIds = $roles
            ->filter(function (Role $role) use ($isSuperAdmin, $grantablePermissionCodes): bool {
                if ($isSuperAdmin) {
                    return true;
                }

                if ($role->code === 'SUPER_ADMIN') {
                    return false;
                }

                return $role->permissions->pluck('code')->diff($grantablePermissionCodes ?? collect())->isEmpty();
            })
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $enterpriseSecurity = $enterpriseSecurityAdmin->dashboard($actor);
        $pendingSecuritySummaries = $enterpriseSecurity['pending_changes']->mapWithKeys(
            fn (EnterpriseWorkflowRequest $workflow): array => [
                (int) $workflow->getKey() => $enterpriseSecurityAdmin->summarizeRequest($workflow),
            ],
        );
        $enterprisePolicyCandidates = User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name', 'email']);

        return view('admin.security', [
            'users' => $users,
            'roles' => $roles,
            'globalRoles' => $globalRoles,
            'grantableStoreRolesByStore' => $grantableStoreRolesByStore,
            'stores' => $stores,
            'permissionGroups' => $permissionGroups,
            'permissionLabels' => $permissionLabels,
            'permissionGroupLabels' => $permissionGroupLabels,
            'editableRoleIds' => $editableRoleIds,
            'privilegedPermissionCodes' => [
                'platform.manage',
                'security.view',
                'roles.manage',
                'users.view',
                'users.roles.manage',
                'users.status.manage',
                'translations.manage',
                'system.update',
                'demo_data.manage',
            ],
            'search' => $search,
            'statusFilter' => $status,
            'demoSummary' => $demoData->summary(),
            'enterpriseSecurityAvailable' => $enterpriseSecurityAdmin->available(),
            'enterpriseSecurity' => $enterpriseSecurity,
            'pendingSecuritySummaries' => $pendingSecuritySummaries,
            'enterprisePolicyCandidates' => $enterprisePolicyCandidates,
            'delegationPermissionOptions' => $permissions,
        ]);
    }

    public function demoData(Request $request, DemoDataManager $demoData): View
    {
        Gate::authorize('demo_data.manage');
        $this->useActorLocale($request);

        return view('admin.demo-data', [
            'demoSummary' => $demoData->summary(),
            'environment' => App::environment(),
        ]);
    }

    public function seedDemoData(Request $request, DemoDataManager $demoData): RedirectResponse
    {
        Gate::authorize('demo_data.manage');

        $actor = $this->actor($request);
        $before = $demoData->summary();

        Artisan::call('db:seed', [
            '--class' => ProductDemoSeeder::class,
            '--force' => true,
        ]);

        $after = $demoData->summary();

        DB::table('audit_logs')->insert([
            'user_id' => $actor->id,
            'event' => 'demo_data.seeded',
            'auditable_type' => 'demo_data',
            'auditable_id' => null,
            'before' => json_encode($before, JSON_THROW_ON_ERROR),
            'after' => json_encode($after, JSON_THROW_ON_ERROR),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('status', __('admin.security.demo_data.seeded'));
    }

    public function clearDemoData(Request $request, DemoDataManager $demoData): RedirectResponse
    {
        Gate::authorize('demo_data.manage');

        $request->validate([
            'confirmation' => ['required', Rule::in(['DELETE DEMO DATA', 'حذف بيانات العرض'])],
        ]);

        $actor = $this->actor($request);
        $before = $demoData->summary();

        DB::table('audit_logs')->insert([
            'user_id' => $actor->id,
            'event' => 'demo_data.cleared',
            'auditable_type' => 'demo_data',
            'auditable_id' => null,
            'before' => json_encode($before, JSON_THROW_ON_ERROR),
            'after' => json_encode(array_fill_keys(array_keys($before), 0), JSON_THROW_ON_ERROR),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $demoData->clear();

        return back()->with('status', __('admin.security.demo_data.cleared'));
    }

    public function updateUserStatus(Request $request, User $user, RbacManager $rbac): RedirectResponse
    {
        Gate::authorize('users.status.manage');
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $rbac->setUserActive(
                $this->actor($request),
                $user,
                (bool) $validated['is_active'],
                $validated['reason'] ?? null,
                $request,
            );
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return back()->with('status', (bool) $validated['is_active']
            ? __('admin.security.user_activated')
            : __('admin.security.user_deactivated'));
    }

    public function updateUserRoles(Request $request, User $user, RbacManager $rbac): RedirectResponse
    {
        Gate::authorize('users.roles.manage');
        $validated = $request->validate([
            'global_role_ids' => ['nullable', 'array'],
            'global_role_ids.*' => ['integer', 'exists:roles,id'],
            'store_role_ids' => ['nullable', 'array'],
            'store_role_ids.*' => ['array'],
            'store_role_ids.*.*' => ['integer', 'exists:roles,id'],
        ]);

        $storeRoles = [];

        foreach (($validated['store_role_ids'] ?? []) as $storeId => $roleIds) {
            foreach ((array) $roleIds as $roleId) {
                $storeRoles[] = ['store_id' => (int) $storeId, 'role_id' => (int) $roleId];
            }
        }

        $rbac->replaceUserRoles(
            $this->actor($request),
            $user,
            array_map('intval', $validated['global_role_ids'] ?? []),
            $storeRoles,
            $request,
        );

        return back()->with('status', __('admin.security.user_roles_saved'));
    }

    public function storeRole(Request $request, RbacManager $rbac): RedirectResponse
    {
        Gate::authorize('roles.manage');
        $validated = $this->validateRole($request, true);
        $rbac->createRole($this->actor($request), $validated['code'], $this->roleValues($validated), $request);

        return back()->with('status', __('admin.security.role_created'));
    }

    public function updateRole(Request $request, Role $role, RbacManager $rbac): RedirectResponse
    {
        Gate::authorize('roles.manage');
        $validated = $this->validateRole($request);
        $rbac->updateRole($this->actor($request), $role, $this->roleValues($validated), $request);

        return back()->with('status', __('admin.security.role_saved'));
    }

    public function cloneRole(Request $request, Role $role, RbacManager $rbac): RedirectResponse
    {
        Gate::authorize('roles.manage');
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Z][A-Z0-9_]{2,49}$/', Rule::unique('roles', 'code')],
            'name' => ['required', 'string', 'max:120'],
        ]);

        $rbac->cloneRole($this->actor($request), $role, $validated['code'], $validated['name'], $request);

        return back()->with('status', __('admin.security.role_cloned'));
    }

    public function destroyRole(Request $request, Role $role, RbacManager $rbac): RedirectResponse
    {
        Gate::authorize('roles.manage');
        $rbac->deleteRole($this->actor($request), $role, $request);

        return back()->with('status', __('admin.security.role_deleted'));
    }

    public function requestEnterpriseChange(
        Request $request,
        EnterpriseSecurityAdministration $enterpriseSecurityAdmin,
    ): RedirectResponse {
        Gate::authorize('roles.manage');

        $base = $request->validate([
            'operation' => ['required', Rule::in([
                EnterpriseSecurityAdministration::DATA_SCOPE_UPSERT,
                EnterpriseSecurityAdministration::DATA_SCOPE_REMOVE,
                EnterpriseSecurityAdministration::APPROVAL_LIMIT_UPSERT,
                EnterpriseSecurityAdministration::APPROVAL_LIMIT_REMOVE,
                EnterpriseSecurityAdministration::DELEGATION_CREATE,
                EnterpriseSecurityAdministration::DELEGATION_REVOKE,
            ])],
            'request_token' => ['required', 'uuid'],
        ]);

        $operation = (string) $base['operation'];
        $payload = [];

        if ($operation === EnterpriseSecurityAdministration::DATA_SCOPE_UPSERT) {
            $validated = $request->validate([
                'target_user_id' => ['required', 'integer', 'exists:users,id'],
                'scope_reference' => ['required', 'string', 'max:190'],
                'starts_at' => ['nullable', 'date'],
                'ends_at' => ['nullable', 'date'],
            ]);
            $scope = $enterpriseSecurityAdmin->resolveScopeReference(
                (string) $validated['scope_reference'],
                $this->actor($request),
                false,
            );
            $payload = [
                'target_user_id' => (int) $validated['target_user_id'],
                ...$scope,
                'starts_at' => $validated['starts_at'] ?? null,
                'ends_at' => $validated['ends_at'] ?? null,
            ];
        } elseif ($operation === EnterpriseSecurityAdministration::APPROVAL_LIMIT_UPSERT) {
            $validated = $request->validate([
                'target_user_id' => ['required', 'integer', 'exists:users,id'],
                'workflow_key' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._*:-]+$/'],
                'currency' => ['required', 'string', 'size:3', 'alpha'],
                'amount' => ['required', 'regex:/^\\d{1,14}(?:\\.\\d{1,4})?$/'],
                'scope_reference' => ['required', 'string', 'max:190'],
                'starts_at' => ['nullable', 'date'],
                'ends_at' => ['nullable', 'date'],
            ]);
            $scope = $enterpriseSecurityAdmin->resolveScopeReference(
                (string) $validated['scope_reference'],
                $this->actor($request),
            );
            $payload = [
                'target_user_id' => (int) $validated['target_user_id'],
                'workflow_key' => (string) $validated['workflow_key'],
                'currency' => strtoupper((string) $validated['currency']),
                'amount' => (string) $validated['amount'],
                ...$scope,
                'starts_at' => $validated['starts_at'] ?? null,
                'ends_at' => $validated['ends_at'] ?? null,
            ];
        } elseif ($operation === EnterpriseSecurityAdministration::DELEGATION_CREATE) {
            $validated = $request->validate([
                'delegate_id' => ['required', 'integer', 'exists:users,id'],
                'permission_code' => ['required', 'string', 'max:160', 'exists:permissions,code'],
                'scope_reference' => ['required', 'string', 'max:190'],
                'starts_at' => ['required', 'date'],
                'ends_at' => ['required', 'date', 'after:starts_at'],
            ]);
            $scope = $enterpriseSecurityAdmin->resolveScopeReference(
                (string) $validated['scope_reference'],
                $this->actor($request),
            );
            $payload = [
                'delegate_id' => (int) $validated['delegate_id'],
                'permission_code' => (string) $validated['permission_code'],
                ...$scope,
                'starts_at' => (string) $validated['starts_at'],
                'ends_at' => (string) $validated['ends_at'],
            ];
        } else {
            $validated = $request->validate([
                'policy_id' => ['required', 'integer', 'min:1'],
            ]);
            $payload = ['policy_id' => (int) $validated['policy_id']];
        }

        $enterpriseSecurityAdmin->requestChange(
            $this->actor($request),
            $operation,
            $payload,
            (string) $base['request_token'],
        );

        return back()->with('status', __('enterprise_security.change_requested'));
    }

    public function decideEnterpriseChange(
        Request $request,
        EnterpriseWorkflowRequest $workflow,
        EnterpriseSecurityAdministration $enterpriseSecurityAdmin,
    ): RedirectResponse {
        Gate::authorize('roles.manage');

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['APPROVED', 'REJECTED'])],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $enterpriseSecurityAdmin->decideChange(
            $workflow,
            $this->actor($request),
            (string) $validated['decision'],
            $validated['comment'] ?? null,
        );

        return back()->with(
            'status',
            $validated['decision'] === 'APPROVED'
                ? __('enterprise_security.change_approved')
                : __('enterprise_security.change_rejected'),
        );
    }

    /** @return array<string, mixed> */
    private function validateRole(Request $request, bool $includeCode = false): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'scope' => ['required', Rule::in(['global', 'store'])],
            'is_active' => ['required', 'boolean'],
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['integer', 'distinct', 'exists:permissions,id'],
        ];

        if ($includeCode) {
            $rules['code'] = ['required', 'string', 'max:50', 'regex:/^[A-Z][A-Z0-9_]{2,49}$/', Rule::unique('roles', 'code')];
        }

        return $request->validate($rules);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{name:string,description:?string,scope:string,is_active:bool,permission_ids:list<int>}
     */
    private function roleValues(array $validated): array
    {
        return [
            'name' => (string) $validated['name'],
            'description' => isset($validated['description']) && trim((string) $validated['description']) !== ''
                ? trim((string) $validated['description'])
                : null,
            'scope' => (string) $validated['scope'],
            'is_active' => (bool) $validated['is_active'],
            'permission_ids' => array_map('intval', $validated['permission_ids'] ?? []),
        ];
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function useActorLocale(Request $request): void
    {
        $actor = $this->actor($request);
        App::setLocale(in_array($actor->locale, ['ar', 'en'], true) ? $actor->locale : 'ar');
    }
}

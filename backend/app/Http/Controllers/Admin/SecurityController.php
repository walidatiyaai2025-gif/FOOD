<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminMfaCredential;
use App\Models\AdminSecuritySession;
use App\Models\EnterpriseApprovalLimit;
use App\Models\EnterpriseBusinessUnit;
use App\Models\EnterpriseCompany;
use App\Models\EnterpriseDelegation;
use App\Models\EnterpriseLegalEntity;
use App\Models\EnterpriseOrganization;
use App\Models\EnterpriseRoleDataScope;
use App\Models\EnterpriseSsoIdentity;
use App\Models\EnterpriseWorkflowRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\UserStoreRole;
use App\Services\AdminSecurityPolicy;
use App\Services\AdminSessionRegistry;
use App\Services\AuditLogger;
use App\Services\BuiltInJobRoleCatalog;
use App\Services\DemoDataManager;
use App\Services\RbacManager;
use App\Services\RoleAssignmentAudit;
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
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SecurityController extends Controller
{
    public function index(Request $request, DemoDataManager $demoData, BuiltInJobRoleCatalog $jobRoles, RoleAssignmentAudit $assignmentAudit): View
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
        $assignment = (string) $request->query('assignment', 'all');
        $section = (string) $request->query('section', 'users');

        if (! in_array($status, ['all', 'active', 'inactive'], true)) {
            $status = 'all';
        }
        if (! in_array($assignment, ['all', 'roleless'], true)) {
            $assignment = 'all';
        }
        if (! in_array($section, ['users', 'roles', 'governance', 'sessions', 'audit'], true)) {
            $section = 'users';
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
            // T015R05: distinguish genuinely roleless identities without inventing grants.
            // Both global and store assignments count; do not treat customers as staff defects.
            ->when($assignment === 'roleless', fn ($query) => $query
                ->whereDoesntHave('roles')
                ->whereDoesntHave('storeRoleAssignments'))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        $roles = Role::query()
            ->with('permissions:id,code,name')
            ->withCount(['users', 'storeAssignments'])
            ->orderByDesc('is_system')
            ->orderBy('code')
            ->get();

        // P098: show stored, active role approval ceilings without inferring
        // effective user access or a store assignment from the role itself.
        $roleApprovalLimits = $section === 'roles'
            ? EnterpriseApprovalLimit::query()
                ->whereIn('role_id', $roles->pluck('id'))
                ->where('is_active', true)
                ->orderBy('permission_code')
                ->orderBy('currency')
                ->get()
                ->groupBy('role_id')
            : collect();

        // Role data-scope grants are separately governed in Enterprise Security.
        // Resolve only referenced entity labels, preserving orphaned historical
        // IDs as unavailable rather than presenting them as live authority.
        $roleDataScopeRows = $section === 'roles'
            ? EnterpriseRoleDataScope::query()
                ->whereIn('role_id', $roles->pluck('id'))
                ->where('is_active', true)
                ->orderBy('scope_type')
                ->get()
            : collect();
        $roleDataScopes = $roleDataScopeRows->groupBy('role_id');
        $roleDataScopeNames = collect();
        foreach ([
            'organization' => EnterpriseOrganization::class,
            'company' => EnterpriseCompany::class,
            'legal_entity' => EnterpriseLegalEntity::class,
            'business_unit' => EnterpriseBusinessUnit::class,
            'store' => Store::class,
        ] as $scopeType => $modelClass) {
            $ids = $roleDataScopeRows->where('scope_type', $scopeType)
                ->pluck('scope_id')
                ->filter()
                ->unique()
                ->all();
            if ($ids === []) {
                continue;
            }

            $modelClass::query()->whereIn('id', $ids)->where('is_active', true)->get(['id', 'name'])
                ->each(fn ($record) => $roleDataScopeNames->put($scopeType.':'.$record->id, (string) $record->name));
        }

        // Store names are read from actual role assignments; no inferred store
        // ownership or per-user effective authorization is implied.
        $roleAssignedStores = $section === 'roles'
            ? DB::table('user_store_roles')
                ->join('stores', 'stores.id', '=', 'user_store_roles.store_id')
                ->whereIn('user_store_roles.role_id', $roles->pluck('id'))
                ->select('user_store_roles.role_id', 'stores.code as store_code', 'stores.name as store_name')
                ->selectRaw('COUNT(*) as assignments_count')
                ->groupBy('user_store_roles.role_id', 'stores.id', 'stores.code', 'stores.name')
                ->orderBy('stores.name')
                ->get()
                ->groupBy('role_id')
            : collect();

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

        $userGrantablePermissionCodes = $isSuperAdmin
            ? null
            : $stores->reduce(
                fn ($codes, Store $store) => $codes->merge($actor->effectivePermissionCodes((int) $store->id)),
                collect($actor->effectivePermissionCodes()),
            )->unique()->values();

        $userPermissions = Permission::query()
            ->orderBy('code')
            ->get()
            ->when(! $isSuperAdmin, fn ($items) => $items
                ->filter(fn (Permission $permission): bool => $userGrantablePermissionCodes?->contains($permission->code) === true)
                ->values());
        $userPermissionGroups = $userPermissions->groupBy(
            static fn (Permission $permission): string => Str::before($permission->code, '.'),
        );
        $userPermissionLabels = $userPermissions->mapWithKeys(function (Permission $permission) use (
            $groupTranslations,
            $actionTranslations,
        ): array {
            $modulePath = Str::beforeLast($permission->code, '.');
            $rootModule = Str::before($permission->code, '.');
            $action = Str::afterLast($permission->code, '.');
            $moduleLabel = $groupTranslations[$modulePath]
                ?? $groupTranslations[$rootModule]
                ?? Str::headline(str_replace('_', ' ', $modulePath));
            $actionLabel = $actionTranslations[$action] ?? Str::headline(str_replace('_', ' ', $action));

            return [(int) $permission->id => $moduleLabel.' — '.$actionLabel];
        });
        $userPermissionGroupLabels = $userPermissionGroups->keys()->mapWithKeys(
            fn (string $module): array => [
                $module => $groupTranslations[$module] ?? Str::headline(str_replace('_', ' ', $module)),
            ],
        )->all();
        $userPermissionIdsByCode = $userPermissions->pluck('id', 'code');

        $permissionCrossesRetailBoundary = static fn (string $code): bool => str_starts_with($code, 'b2b.')
            || str_starts_with($code, 'drivers.b2b.')
            || str_starts_with($code, 'deliveries.b2b.')
            || in_array($code, [
                'platform.manage',
                'security.view',
                'roles.manage',
                'users.view',
                'users.delete',
                'users.roles.manage',
                'users.status.manage',
                'translations.manage',
                'system.update',
                'demo_data.manage',
                'mobile_settings.manage',
                'push_settings.manage',
                'push_settings.test',
            ], true);

        $userGlobalPermissionGroupsByUser = $users->getCollection()->mapWithKeys(
            function (User $managedUser) use ($permissions, $permissionCrossesRetailBoundary): array {
                $roleCodes = $managedUser->roles
                    ->where('is_active', true)
                    ->pluck('code')
                    ->map(static fn ($value): string => (string) $value);
                $retailDomain = $roleCodes->contains(
                    static fn (string $code): bool => $code === 'B2C_DRIVER' || str_starts_with($code, 'RETAIL_'),
                );
                $wholesaleDomain = $roleCodes->contains(
                    static fn (string $code): bool => $code !== 'B2C_DRIVER' && ! str_starts_with($code, 'RETAIL_'),
                );

                $groups = $permissions
                    ->filter(function (Permission $permission) use (
                        $retailDomain,
                        $wholesaleDomain,
                        $permissionCrossesRetailBoundary,
                    ): bool {
                        $code = (string) $permission->code;
                        $allowedByRetail = $retailDomain && ! $permissionCrossesRetailBoundary($code);
                        $allowedByWholesale = $wholesaleDomain
                            && ! str_starts_with($code, 'drivers.b2c.')
                            && ! str_starts_with($code, 'deliveries.b2c.');

                        return $allowedByRetail || $allowedByWholesale;
                    })
                    ->values()
                    ->groupBy(static fn (Permission $permission): string => Str::before($permission->code, '.'));

                return [(int) $managedUser->id => $groups];
            },
        );

        $userPermissionGroupsByStore = $stores->mapWithKeys(function (Store $store) use (
            $isSuperAdmin,
            $actor,
            $userPermissions,
            $permissionCrossesRetailBoundary,
        ): array {
            $allowedCodes = $isSuperAdmin
                ? null
                : collect($actor->effectivePermissionCodes((int) $store->id));

            $groups = $userPermissions
                ->when(! $isSuperAdmin, fn ($items) => $items
                    ->filter(fn (Permission $permission): bool => $allowedCodes?->contains($permission->code) === true)
                    ->values())
                ->filter(fn (Permission $permission): bool => ! $permissionCrossesRetailBoundary((string) $permission->code))
                ->values()
                ->groupBy(static fn (Permission $permission): string => Str::before($permission->code, '.'));

            return [(int) $store->id => $groups];
        });

        $globalCandidates = $roles->where('scope', 'global')->where('is_active', true)->values();
        $storeCandidates = $roles->where('scope', 'store')->where('is_active', true)->values();

        $jobRoleMeta = $jobRoles->presentationMap();
        $globalRoleAvailability = $globalCandidates->mapWithKeys(
            fn (Role $role): array => [(int) $role->id => $jobRoles->isAvailable((string) $role->code, null)],
        );
        $storeRoleAvailabilityByStore = $stores->mapWithKeys(
            fn (Store $store): array => [
                (int) $store->id => $storeCandidates->mapWithKeys(
                    fn (Role $role): array => [
                        (int) $role->id => $jobRoles->isAvailable((string) $role->code, (int) $store->id),
                    ],
                ),
            ],
        );
        $roleAvailabilitySummary = $roles->mapWithKeys(function (Role $role) use (
            $jobRoles,
            $stores,
            $storeRoleAvailabilityByStore,
        ): array {
            if ($role->scope === 'global') {
                return [(int) $role->id => $jobRoles->isAvailable((string) $role->code, null)];
            }

            return [
                (int) $role->id => $stores->contains(
                    fn (Store $store): bool => (bool) $storeRoleAvailabilityByStore
                        ->get((int) $store->id, collect())
                        ->get((int) $role->id, false),
                ),
            ];
        });

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

        $roleOutsideGrantAuthorityIds = $isSuperAdmin
            ? []
            : $roles
                ->filter(fn (Role $role): bool => $role->permissions
                    ->pluck('code')
                    ->diff($grantablePermissionCodes ?? collect())
                    ->isNotEmpty())
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

        $editableRoleIds = $roles
            ->filter(function (Role $role) use ($isSuperAdmin, $grantablePermissionCodes, $jobRoles): bool {
                if ($jobRoles->isBuiltIn((string) $role->code)) {
                    return false;
                }

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

        $governance = $this->governanceSnapshot($request, $actor);
        $mfaByUser = AdminMfaCredential::query()
            ->whereIn('user_id', $users->getCollection()->pluck('id'))
            ->whereNotNull('enabled_at')
            ->whereNull('revoked_at')
            ->get()
            ->keyBy('user_id');
        $ssoByUser = EnterpriseSsoIdentity::query()
            ->whereIn('user_id', $users->getCollection()->pluck('id'))
            ->get()
            ->groupBy('user_id');
        $effectivePermissionsByUser = $users->getCollection()
            ->mapWithKeys(fn (User $managedUser): array => [
                (int) $managedUser->id => $managedUser->effectivePermissionCodes(),
            ]);
        $effectivePermissionIdsByUser = $users->getCollection()
            ->mapWithKeys(function (User $managedUser) use ($userPermissionIdsByCode): array {
                $ids = collect($managedUser->effectivePermissionCodes())
                    ->map(fn (string $code) => $userPermissionIdsByCode->get($code))
                    ->filter()
                    ->map(static fn ($id): int => (int) $id)
                    ->values()
                    ->all();

                return [(int) $managedUser->id => $ids];
            });
        $effectiveStorePermissionIdsByUser = $users->getCollection()
            ->mapWithKeys(function (User $managedUser) use ($userPermissionIdsByCode): array {
                $storeMap = $managedUser->storeRoleAssignments
                    ->pluck('store_id')
                    ->unique()
                    ->mapWithKeys(function ($storeId) use ($managedUser, $userPermissionIdsByCode): array {
                        $ids = collect($managedUser->effectivePermissionCodes((int) $storeId))
                            ->map(fn (string $code) => $userPermissionIdsByCode->get($code))
                            ->filter()
                            ->map(static fn ($id): int => (int) $id)
                            ->values()
                            ->all();

                        return [(int) $storeId => $ids];
                    })
                    ->all();

                return [(int) $managedUser->id => $storeMap];
            });
        // P081: describe the user's server-resolved RBAC state, never a UI-only grant.
        // The existing authorization engine remains the only source of effective access.
        $userPermissionStatesByUser = $users->getCollection()
            ->mapWithKeys(function (User $managedUser) use ($userPermissions): array {
                $statesForScope = static function (?int $storeId) use ($managedUser, $userPermissions): array {
                    $effects = $managedUser->permissionOverrideEffects($storeId);
                    $effective = array_fill_keys($managedUser->effectivePermissionCodes($storeId), true);
                    $inherited = array_fill_keys($managedUser->inheritedPermissionCodes($storeId), true);

                    return $userPermissions->mapWithKeys(static function (Permission $permission) use (
                        $effects,
                        $effective,
                        $inherited,
                    ): array {
                        $code = (string) $permission->code;
                        $effect = $effects[$code] ?? null;

                        $state = match (true) {
                            $effect === 'deny' => 'deny',
                            ! isset($effective[$code]) => 'blocked',
                            $effect === 'allow' => 'allow',
                            isset($inherited[$code]) => 'inherited',
                            default => 'blocked',
                        };

                        return [(int) $permission->id => $state];
                    })->all();
                };

                $stores = $managedUser->storeRoleAssignments
                    ->pluck('store_id')
                    ->unique()
                    ->mapWithKeys(static fn ($storeId): array => [
                        (int) $storeId => $statesForScope((int) $storeId),
                    ])
                    ->all();

                return [(int) $managedUser->id => [
                    'global' => $statesForScope(null),
                    'stores' => $stores,
                ]];
            });
        $permissionOverrideCountsByUser = DB::table('user_permission_overrides')
            ->whereIn('user_id', $users->getCollection()->pluck('id'))
            ->selectRaw('user_id, COUNT(*) as aggregate')
            ->groupBy('user_id')
            ->pluck('aggregate', 'user_id')
            ->map(static fn ($count): int => (int) $count);
        $securitySessions = AdminSecuritySession::query()
            ->with('user:id,name,email')
            ->whereNull('revoked_at')
            ->orderByDesc('last_seen_at')
            ->limit(50)
            ->get();
        $audit = $this->auditExplorer($request);

        // T015R04: raw global/store assignment rows are visible to Super Admin only.
        $roleAssignmentAudit = $section === 'roles' && $isSuperAdmin
            ? $assignmentAudit->snapshot()
            : null;

        // T015R03: source-to-current audit is restricted to Super Admin.
        // The pivot records grants as stored; it cannot represent effective ALLOW/DENY,
        // tenant, module readiness or financial SoD decisions by itself.
        $permissionRoleAudit = null;
        if ($section === 'roles' && $isSuperAdmin) {
            $catalogCodes = Permission::query()->orderBy('code')->pluck('code');
            $linkedCodes = DB::table('permission_role')
                ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->pluck('permissions.code');
            $linkedByNamespace = $linkedCodes->groupBy(
                static fn (string $code): string => Str::before($code, '.'),
            );

            $permissionRoleAudit = [
                'permission_count' => $catalogCodes->count(),
                'relation_count' => $linkedCodes->count(),
                'unused_count' => Permission::query()->whereDoesntHave('roles')->count(),
                'active_role_relation_count' => DB::table('permission_role')
                    ->join('roles', 'roles.id', '=', 'permission_role.role_id')
                    ->where('roles.is_active', true)
                    ->whereNull('roles.archived_at')
                    ->count(),
                'namespaces' => $catalogCodes->groupBy(
                    static fn (string $code): string => Str::before($code, '.'),
                )->map(static fn ($codes, string $namespace): array => [
                    'code' => $namespace,
                    'permissions' => $codes->count(),
                    'relations' => $linkedByNamespace->get($namespace, collect())->count(),
                ])->values()->all(),
            ];
        }

        return view('admin.security', [
            'users' => $users,
            'roles' => $roles,
            'roleApprovalLimits' => $roleApprovalLimits,
            'roleAssignedStores' => $roleAssignedStores,
            'roleDataScopes' => $roleDataScopes,
            'roleDataScopeNames' => $roleDataScopeNames,
            'permissionRoleAudit' => $permissionRoleAudit,
            'roleAssignmentAudit' => $roleAssignmentAudit,
            'globalRoles' => $globalRoles,
            'grantableStoreRolesByStore' => $grantableStoreRolesByStore,
            'jobRoleMeta' => $jobRoleMeta,
            'globalRoleAvailability' => $globalRoleAvailability,
            'storeRoleAvailabilityByStore' => $storeRoleAvailabilityByStore,
            'roleAvailabilitySummary' => $roleAvailabilitySummary,
            'stores' => $stores,
            'permissionGroups' => $permissionGroups,
            'permissionLabels' => $permissionLabels,
            'permissionGroupLabels' => $permissionGroupLabels,
            'userPermissionGroups' => $userPermissionGroups,
            'userPermissionLabels' => $userPermissionLabels,
            'userPermissionGroupLabels' => $userPermissionGroupLabels,
            'userPermissionGroupsByStore' => $userPermissionGroupsByStore,
            'userGlobalPermissionGroupsByUser' => $userGlobalPermissionGroupsByUser,
            'editableRoleIds' => $editableRoleIds,
            'roleOutsideGrantAuthorityIds' => $roleOutsideGrantAuthorityIds,
            'privilegedPermissionCodes' => [
                'platform.manage',
                'security.view',
                'roles.manage',
                'users.view',
                'users.delete',
                'users.roles.manage',
                'users.status.manage',
                'translations.manage',
                'system.update',
                'demo_data.manage',
            ],
            'search' => $search,
            'statusFilter' => $status,
            'assignmentFilter' => $assignment,
            'securitySection' => $section,
            'demoSummary' => $demoData->summary(),
            'governance' => $governance,
            'mfaByUser' => $mfaByUser,
            'ssoByUser' => $ssoByUser,
            'effectivePermissionsByUser' => $effectivePermissionsByUser,
            'effectivePermissionIdsByUser' => $effectivePermissionIdsByUser,
            'effectiveStorePermissionIdsByUser' => $effectiveStorePermissionIdsByUser,
            'userPermissionStatesByUser' => $userPermissionStatesByUser,
            'permissionOverrideCountsByUser' => $permissionOverrideCountsByUser,
            'securitySessions' => $securitySessions,
            'currentSecuritySessionHash' => hash('sha256', $request->session()->getId().'|'.$actor->id),
            'auditRows' => $audit['rows'],
            'auditFilters' => $audit['filters'],
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

    public function storeUser(
        Request $request,
        RbacManager $rbac,
        AuditLogger $audit,
    ): RedirectResponse {
        Gate::authorize('users.roles.manage');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'username' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users', 'username')],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:10', 'max:100', 'confirmed'],
            'locale' => ['required', Rule::in(['ar', 'en'])],
            'role_id' => ['nullable', 'integer', 'exists:roles,id', 'required_without:store_role_assignment'],
            'store_role_assignment' => ['nullable', 'string', 'regex:/^[1-9][0-9]*:[1-9][0-9]*$/'],
            // Optional for pre-existing API/automation clients; required by the new Dashboard selector.
            'account_scope' => ['sometimes', Rule::in(['global', 'store', 'mixed'])],
            'is_active' => ['required', 'boolean'],
        ]);

        $actor = $this->actor($request);
        $globalRoleIds = isset($validated['role_id']) ? [(int) $validated['role_id']] : [];
        $storeRoles = [];
        if (! empty($validated['store_role_assignment'])) {
            [$storeId, $storeRoleId] = array_map('intval', explode(':', $validated['store_role_assignment'], 2));
            $storeRoles[] = ['store_id' => $storeId, 'role_id' => $storeRoleId];
        }

        // Account type/channel is a projection of canonical role assignments,
        // never a new stored grant. Existing integration clients without this
        // field retain their validated role/store assignment semantics.
        if (isset($validated['account_scope'])) {
            $hasGlobal = $globalRoleIds !== [];
            $hasStore = $storeRoles !== [];
            $actualScope = $hasGlobal && $hasStore ? 'mixed' : ($hasGlobal ? 'global' : 'store');
            if ($actualScope !== $validated['account_scope']) {
                throw ValidationException::withMessages([
                    'account_scope' => [__('admin.security.account_scope_mismatch')],
                ]);
            }
        }

        DB::transaction(function () use ($validated, $actor, $globalRoleIds, $storeRoles, $request, $rbac, $audit): void {
            $username = trim((string) ($validated['username'] ?? ''));
            $user = User::query()->create([
                'name' => trim((string) $validated['name']),
                'username' => $username === '' ? null : strtolower($username),
                'email' => strtolower(trim((string) $validated['email'])),
                'password' => (string) $validated['password'],
                'locale' => (string) $validated['locale'],
                'is_active' => (bool) $validated['is_active'],
                'is_platform_customer' => false,
            ]);

            $rbac->replaceUserRoles($actor, $user, $globalRoleIds, $storeRoles, $request);
            $fresh = $user->refresh()->load(['roles:id,code,name', 'storeRoleAssignments.role:id,code,name']);

            $audit->record(
                'user.created',
                $actor,
                $fresh,
                null,
                [
                    'username' => (string) $fresh->username,
                    'email' => (string) $fresh->email,
                    'locale' => (string) $fresh->locale,
                    'is_active' => (bool) $fresh->is_active,
                    'global_roles' => $fresh->roles->pluck('code')->sort()->values()->all(),
                    'store_roles' => $fresh->storeRoleAssignments->map(static fn ($assignment): array => [
                        'store_id' => (int) $assignment->store_id,
                        'role_code' => (string) $assignment->role->code,
                    ])->values()->all(),
                ],
                $request,
            );
        }, 3);

        return redirect()
            ->route('admin.security.index', ['section' => 'users'])
            ->with('status', __('admin.security.user_created'));
    }

    /**
     * Identity-only edit: role and channel/store authority remain exclusively
     * with the canonical audited RbacManager role assignment endpoints.
     */
    public function updateUserProfile(
        Request $request,
        User $user,
        AuditLogger $audit,
        AdminSessionRegistry $sessions,
    ): RedirectResponse {
        Gate::authorize('users.roles.manage');
        $actor = $this->actor($request);

        // Identity takeover is too sensitive for delegated role managers; owner,
        // SUPER_ADMIN and consumer identities have their own governance paths.
        abort_unless($actor->hasRole('SUPER_ADMIN'), 403);
        abort_if(
            $user->isPlatformOwner() || $user->hasRole('SUPER_ADMIN') || (bool) $user->is_platform_customer,
            403,
        );

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'username' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users', 'username')->ignore($user->id)],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'locale' => ['required', Rule::in(['ar', 'en'])],
            'change_reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        $before = [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'locale' => $user->locale,
        ];
        $username = trim((string) ($validated['username'] ?? ''));
        $email = strtolower(trim((string) $validated['email']));
        $emailChanged = $email !== strtolower((string) $user->email);
        $usernameChanged = ($username === '' ? null : strtolower($username)) !== $user->username;
        $loginChanged = $emailChanged || $usernameChanged;

        DB::transaction(function () use ($user, $actor, $request, $audit, $sessions, $validated, $before, $username, $email, $emailChanged, $loginChanged): void {
            $user->fill([
                'name' => trim((string) $validated['name']),
                'username' => $username === '' ? null : strtolower($username),
                'email' => $email,
                'locale' => (string) $validated['locale'],
            ]);
            // A changed email or username invalidates old login sessions.
            if ($loginChanged) {
                $user->forceFill(['remember_token' => Str::random(60)]);
            }
            if ($emailChanged) {
                $user->forceFill(['email_verified_at' => null]);
            }
            $user->save();

            if ($loginChanged) {
                $user->tokens()->delete();
                $sessions->revokeForUser($user, $actor);
            }

            $audit->record('user.profile_updated', $actor, $user, $before, [
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'locale' => $user->locale,
                'change_reason' => trim((string) $validated['change_reason']),
                'reverify_email' => $emailChanged,
                'login_sessions_revoked' => $loginChanged,
            ], $request);
        }, 3);

        return back()->with('status', __('admin.security.user_profile_saved'));
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
            'change_reason' => ['required', 'string', 'min:8', 'max:500'],
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

    public function updateUserPermissions(Request $request, User $user, RbacManager $rbac): RedirectResponse
    {
        Gate::authorize('users.roles.manage');
        abort_if($user->isPlatformOwner(), 403);

        $validated = $request->validate([
            'scope_type' => ['required', Rule::in(['global', 'store'])],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
            'inherit' => ['sometimes', 'boolean'],
            'change_reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        $inheritFromRole = (bool) ($validated['inherit'] ?? false);

        $rbac->replaceUserPermissions(
            $this->actor($request),
            $user,
            $inheritFromRole ? [] : array_map('intval', $validated['permission_ids'] ?? []),
            (string) $validated['scope_type'],
            isset($validated['store_id']) ? (int) $validated['store_id'] : null,
            $request,
            $inheritFromRole,
        );

        return back()->with('status', __('security_permissions.user_permissions_saved'));
    }

    public function resetUserMfa(
        Request $request,
        User $user,
        AdminSessionRegistry $sessions,
        AuditLogger $audit,
    ): RedirectResponse {
        Gate::authorize('users.roles.manage');
        abort_if($user->isPlatformOwner(), 403);
        $actor = $this->actor($request);

        AdminMfaCredential::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revoked_by' => $actor->id,
                'updated_at' => now(),
            ]);
        $sessions->revokeForUser($user, $actor);
        $audit->record('management.mfa.reset', $actor, $user, null, null, $request);

        return back()->with('status', __('security_governance.mfa_admin.reset_done'));
    }

    public function storeSsoIdentity(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('users.roles.manage');
        abort_if($user->isPlatformOwner(), 403);

        $data = $request->validate([
            'provider' => ['required', 'string', 'max:80'],
            'subject' => [
                'required',
                'string',
                'max:191',
                Rule::unique('enterprise_sso_identities', 'subject')
                    ->where(fn ($query) => $query->where('provider', (string) $request->input('provider')))
                    ->ignore(
                        EnterpriseSsoIdentity::query()
                            ->where('user_id', $user->id)
                            ->where('provider', (string) $request->input('provider'))
                            ->value('id'),
                    ),
            ],
        ]);
        $provider = trim((string) $data['provider']);
        $expectedProvider = (string) config('security.sso.provider', 'enterprise');
        abort_unless(hash_equals($expectedProvider, $provider), 422);

        $identity = EnterpriseSsoIdentity::query()->updateOrCreate(
            ['user_id' => $user->id, 'provider' => $provider],
            ['subject' => trim((string) $data['subject']), 'email' => strtolower($user->email)],
        );

        $audit->record(
            'management.sso.identity_linked',
            $this->actor($request),
            $user,
            null,
            ['provider' => $provider, 'identity_id' => $identity->id],
            $request,
        );

        return back()->with('status', __('security_governance.sso.linked'));
    }

    public function destroySsoIdentity(
        Request $request,
        EnterpriseSsoIdentity $identity,
        AuditLogger $audit,
    ): RedirectResponse {
        Gate::authorize('users.roles.manage');
        $actor = $this->actor($request);
        $user = User::query()->findOrFail($identity->user_id);
        abort_if($user->isPlatformOwner(), 403);
        $provider = $identity->provider;
        $identity->delete();

        $audit->record(
            'management.sso.identity_unlinked',
            $actor,
            $user,
            ['provider' => $provider],
            null,
            $request,
        );

        return back()->with('status', __('security_governance.sso.unlinked'));
    }

    public function revokeSecuritySession(
        Request $request,
        AdminSecuritySession $securitySession,
        AdminSessionRegistry $sessions,
        AuditLogger $audit,
    ): RedirectResponse {
        Gate::authorize('users.status.manage');
        $actor = $this->actor($request);
        $target = User::query()->findOrFail($securitySession->user_id);
        abort_if($target->isPlatformOwner(), 403);
        $sessions->revoke($securitySession, $actor);
        $audit->record(
            'management.session.revoked',
            $actor,
            $target,
            null,
            ['security_session' => $securitySession->public_id],
            $request,
        );

        return back()->with('status', __('security_governance.sessions.revoked'));
    }

    public function exportGovernanceReport(
        Request $request,
        string $report,
        AdminSecurityPolicy $policy,
    ): StreamedResponse {
        Gate::authorize('security.view');
        $actor = $this->actor($request);

        // Effective access contains every user's identity, role and permission inventory.
        // A delegated security.view grant cannot authorize a platform-wide export.
        if ($report === 'effective-access') {
            abort_unless($actor->hasRole('SUPER_ADMIN'), 403);
        }

        $rows = $this->governanceReportRows($report, $policy);

        DB::table('audit_logs')->insert([
            'user_id' => $actor->id,
            'event' => 'security.governance_report.exported',
            'auditable_type' => 'security_report',
            'auditable_id' => null,
            'before' => null,
            'after' => json_encode(['report' => $report, 'rows' => count($rows)], JSON_THROW_ON_ERROR),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->streamDownload(function () use ($rows): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                return;
            }
            if ($rows === []) {
                fputcsv($stream, ['status']);
                fputcsv($stream, ['empty']);

                return;
            }

            fputcsv($stream, array_keys($rows[0]));
            foreach ($rows as $row) {
                fputcsv($stream, array_map(
                    static fn (mixed $value): string => is_scalar($value) || $value === null
                        ? (string) ($value ?? '')
                        : json_encode($value, JSON_THROW_ON_ERROR),
                    $row,
                ));
            }
        }, 'foodex-security-'.$report.'-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
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

        if (! $includeCode) {
            $rules['change_reason'] = ['required', 'string', 'min:8', 'max:500'];
        }

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

    /** @return array<string,mixed> */
    private function governanceSnapshot(Request $request, User $actor): array
    {
        $policy = app(AdminSecurityPolicy::class);
        $activeUsers = User::query()->where('is_active', true)->count();
        $privileged = User::query()
            ->where('is_active', true)
            ->with(['roles.permissions:id,code', 'storeRoleAssignments.role.permissions:id,code'])
            ->get()
            ->filter(fn (User $user): bool => $policy->isPrivileged($user));

        $pendingChanges = EnterpriseWorkflowRequest::query()
            ->where('workflow_key', 'enterprise_security_privileged_change')
            ->where('status', 'PENDING')
            ->count();

        $activeDelegationsQuery = EnterpriseDelegation::query()
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now());
        $activeDelegations = (clone $activeDelegationsQuery)->count();
        $expiringDelegations = (clone $activeDelegationsQuery)
            ->where('ends_at', '<=', now()->addDays(7))
            ->count();

        $approvalExceptions = EnterpriseApprovalLimit::query()
            ->where('enterprise_approval_limits.is_active', true)
            ->join('roles', 'roles.id', '=', 'enterprise_approval_limits.role_id')
            ->where(function ($query): void {
                $query->where('roles.is_active', false)
                    ->orWhere('enterprise_approval_limits.max_amount', '<=', 0);
            })
            ->count();

        $authFailures = DB::table('audit_logs')
            ->where('event', 'management.login.failed')
            ->where('created_at', '>=', now()->subDay())
            ->count();
        $auditExceptions = DB::table('audit_logs')
            ->where('created_at', '>=', now()->subDays(7))
            ->where(function ($query): void {
                $query->where('event', 'like', '%.failed%')
                    ->orWhere('event', 'like', '%.rejected%')
                    ->orWhere('event', 'like', '%.revoked%');
            })
            ->count();

        $mfaEnabledIds = AdminMfaCredential::query()
            ->whereIn('user_id', $privileged->pluck('id'))
            ->whereNotNull('enabled_at')
            ->whereNull('revoked_at')
            ->pluck('user_id');
        $stalePrivileged = $privileged->filter(
            fn (User $user): bool => $user->last_seen_at === null || $user->last_seen_at->lt(now()->subDays(90)),
        )->count();

        $roleRows = Role::query()
            ->withCount(['users', 'storeAssignments'])
            ->orderByDesc('users_count')
            ->limit(12)
            ->get()
            ->map(fn (Role $role): array => [
                'label' => $role->name,
                'scope' => $role->scope,
                'value' => (int) $role->users_count + (int) $role->store_assignments_count,
            ])->all();

        $delegationStates = [
            'active' => $activeDelegations,
            'scheduled' => EnterpriseDelegation::query()->whereNull('revoked_at')->where('starts_at', '>', now())->count(),
            'expired' => EnterpriseDelegation::query()->whereNull('revoked_at')->where('ends_at', '<=', now())->count(),
            'revoked' => EnterpriseDelegation::query()->whereNotNull('revoked_at')->count(),
        ];

        $workflowStates = EnterpriseWorkflowRequest::query()
            ->where('workflow_key', 'enterprise_security_privileged_change')
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($value): int => (int) $value)
            ->all();

        $eventTrend = DB::table('audit_logs')
            ->where('created_at', '>=', now()->subDays(6)->startOfDay())
            ->where(function ($query): void {
                $query->where('event', 'like', 'management.%')
                    ->orWhere('event', 'like', 'enterprise.security.%')
                    ->orWhere('event', 'like', 'security.%');
            })
            ->selectRaw('DATE(created_at) as event_day, COUNT(*) as aggregate')
            ->groupBy('event_day')
            ->orderBy('event_day')
            ->get()
            ->map(fn ($row): array => ['label' => (string) $row->event_day, 'value' => (int) $row->aggregate])
            ->all();

        $sso = $policy->ssoReadiness();

        return [
            'kpis' => [
                'active_users' => $activeUsers,
                'privileged_users' => $privileged->count(),
                'roles' => Role::query()->count(),
                'pending_changes' => $pendingChanges,
                'active_delegations' => $activeDelegations,
                'expiring_delegations' => $expiringDelegations,
                'approval_exceptions' => $approvalExceptions,
                'auth_failures' => $authFailures,
                'audit_exceptions' => $auditExceptions,
            ],
            'role_rows' => $roleRows,
            'delegation_states' => $delegationStates,
            'workflow_states' => $workflowStates,
            'event_trend' => $eventTrend,
            'health' => [
                'privileged_total' => $privileged->count(),
                'mfa_enabled' => $mfaEnabledIds->count(),
                'mfa_missing' => max(0, $privileged->count() - $mfaEnabledIds->count()),
                'mfa_enforced' => (bool) config('security.mfa.enforce_privileged', false),
                'stale_privileged' => $stalePrivileged,
                'risky_delegations' => (clone $activeDelegationsQuery)->where('ends_at', '>', now()->addDays(30))->count(),
                'sso' => $sso,
            ],
        ];
    }

    /** @return array{rows:mixed,filters:array<string,string>} */
    private function auditExplorer(Request $request): array
    {
        $filters = [
            'audit_actor' => trim((string) $request->query('audit_actor', '')),
            'audit_event' => trim((string) $request->query('audit_event', '')),
            'audit_entity' => trim((string) $request->query('audit_entity', '')),
            'audit_from' => trim((string) $request->query('audit_from', '')),
            'audit_to' => trim((string) $request->query('audit_to', '')),
            'audit_correlation' => trim((string) $request->query('audit_correlation', '')),
        ];

        $query = DB::table('audit_logs as audit')
            ->leftJoin('users as actors', 'actors.id', '=', 'audit.user_id')
            ->select([
                'audit.id',
                'audit.event',
                'audit.auditable_type',
                'audit.auditable_id',
                'audit.after',
                'audit.ip_address',
                'audit.created_at',
                'actors.name as actor_name',
                'actors.email as actor_email',
            ]);

        if ($filters['audit_actor'] !== '') {
            $needle = '%'.$filters['audit_actor'].'%';
            $query->where(fn ($nested) => $nested
                ->where('actors.name', 'like', $needle)
                ->orWhere('actors.email', 'like', $needle));
        }
        if ($filters['audit_event'] !== '') {
            $query->where('audit.event', 'like', '%'.$filters['audit_event'].'%');
        }
        if ($filters['audit_entity'] !== '') {
            $query->where('audit.auditable_type', 'like', '%'.$filters['audit_entity'].'%');
        }
        if ($filters['audit_from'] !== '') {
            $query->whereDate('audit.created_at', '>=', $filters['audit_from']);
        }
        if ($filters['audit_to'] !== '') {
            $query->whereDate('audit.created_at', '<=', $filters['audit_to']);
        }
        if ($filters['audit_correlation'] !== '') {
            $query->where('audit.after', 'like', '%'.$filters['audit_correlation'].'%');
        }

        return [
            'rows' => $query->orderByDesc('audit.created_at')->paginate(25, ['*'], 'audit_page')->withQueryString(),
            'filters' => $filters,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function governanceReportRows(string $report, AdminSecurityPolicy $policy): array
    {
        return match ($report) {
            'effective-access' => User::query()
                ->with(['roles:id,code,name,scope', 'storeRoleAssignments.role:id,code,name,scope', 'storeRoleAssignments.store:id,code,name'])
                ->orderBy('id')
                ->get()
                ->map(fn (User $user): array => [
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'active' => $user->is_active ? 'yes' : 'no',
                    'global_roles' => $user->roles->pluck('code')->join('|'),
                    'store_roles' => $user->storeRoleAssignments->map(
                        fn (UserStoreRole $assignment): string => $assignment->store->code.':'.$assignment->role->code,
                    )->join('|'),
                    'effective_permissions' => implode('|', $user->effectivePermissionCodes()),
                ])->all(),
            'privileged-changes' => EnterpriseWorkflowRequest::query()
                ->with('requester:id,name,email')
                ->where('workflow_key', 'enterprise_security_privileged_change')
                ->orderByDesc('created_at')
                ->limit(5000)
                ->get()
                ->map(fn (EnterpriseWorkflowRequest $change): array => [
                    'id' => $change->id,
                    'status' => $change->status->value,
                    'requester' => $change->requester->email,
                    'entity_type' => $change->entity_type,
                    'entity_id' => $change->entity_id,
                    'created_at' => $change->created_at->toIso8601String(),
                    'updated_at' => $change->updated_at->toIso8601String(),
                ])->all(),
            'delegations' => EnterpriseDelegation::query()
                ->with(['delegator:id,email', 'delegate:id,email'])
                ->orderByDesc('created_at')
                ->limit(5000)
                ->get()
                ->map(fn (EnterpriseDelegation $row): array => [
                    'delegator' => $row->delegator->email,
                    'delegate' => $row->delegate->email,
                    'permission' => $row->permission_code,
                    'scope' => $row->scope_key,
                    'starts_at' => $row->starts_at->toIso8601String(),
                    'ends_at' => $row->ends_at->toIso8601String(),
                    'revoked_at' => $row->revoked_at?->toIso8601String(),
                ])->all(),
            'mfa' => User::query()
                ->where('is_active', true)
                ->with(['roles.permissions:id,code', 'storeRoleAssignments.role.permissions:id,code'])
                ->orderBy('id')
                ->get()
                ->filter(fn (User $user): bool => $policy->isPrivileged($user))
                ->map(function (User $user): array {
                    $credential = AdminMfaCredential::query()->where('user_id', $user->id)->first();

                    if ($credential === null) {
                        return [
                            'user_id' => $user->id,
                            'email' => $user->email,
                            'mfa_enabled' => 'no',
                            'enabled_at' => null,
                            'last_verified_at' => null,
                        ];
                    }

                    return [
                        'user_id' => $user->id,
                        'email' => $user->email,
                        'mfa_enabled' => $credential->enabled_at !== null && $credential->revoked_at === null ? 'yes' : 'no',
                        'enabled_at' => $credential->enabled_at?->toIso8601String(),
                        'last_verified_at' => $credential->last_verified_at?->toIso8601String(),
                    ];
                })->values()->all(),
            'auth-audit' => DB::table('audit_logs')
                ->where(function ($query): void {
                    $query->where('event', 'like', 'management.%')
                        ->orWhere('event', 'like', 'enterprise.security.%')
                        ->orWhere('event', 'like', 'security.%');
                })
                ->orderByDesc('created_at')
                ->limit(5000)
                ->get(['user_id', 'event', 'auditable_type', 'auditable_id', 'ip_address', 'created_at'])
                ->map(fn ($row): array => (array) $row)
                ->all(),
            default => abort(404),
        };
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

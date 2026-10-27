<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RbacManager
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BuiltInJobRoleCatalog $jobRoles,
        private readonly RbacDatabaseReconciler $databaseReconciler,
    ) {}

    private function preparePermissionMutation(): void
    {
        $this->databaseReconciler->reconcile();
    }

    private function finalizePermissionMutation(
        User $actor,
        mixed $target,
        string $trigger,
        Request $request,
    ): void {
        $summary = $this->databaseReconciler->reconcile();

        $this->audit->record(
            'rbac.database_reconciled',
            $actor,
            $target,
            null,
            [
                'trigger' => $trigger,
                ...$summary,
            ],
            $request,
        );
    }

    public function setUserActive(User $actor, User $target, bool $active, ?string $reason, Request $request): User
    {
        if (! $active && $actor->is($target)) {
            throw ValidationException::withMessages(['is_active' => [__('admin.security.errors.self_deactivate')]]);
        }

        if (! $active && $this->isLastActiveSuperAdmin($target)) {
            throw ValidationException::withMessages(['is_active' => [__('admin.security.errors.last_super_admin')]]);
        }

        $before = $this->userSnapshot($target);

        DB::transaction(function () use ($target, $active, $reason): void {
            $target->forceFill([
                'is_active' => $active,
                'deactivated_at' => $active ? null : now(),
                'deactivation_reason' => $active ? null : ($reason !== null && trim($reason) !== '' ? trim($reason) : null),
            ])->save();

            if (! $active) {
                $target->tokens()->delete();
            }
        });

        $fresh = $target->refresh();
        $this->audit->record('user.status_changed', $actor, $fresh, $before, $this->userSnapshot($fresh), $request);

        return $fresh;
    }

    /**
     * @param  list<int>  $globalRoleIds
     * @param  list<array{store_id:int,role_id:int}>  $storeRoles
     */
    public function replaceUserRoles(User $actor, User $target, array $globalRoleIds, array $storeRoles, Request $request): User
    {
        $this->preparePermissionMutation();

        $globalRoleIds = array_values(array_unique(array_map('intval', $globalRoleIds)));
        $storeRoles = collect($storeRoles)
            ->map(static fn (array $assignment): array => [
                'store_id' => (int) $assignment['store_id'],
                'role_id' => (int) $assignment['role_id'],
            ])
            ->unique(static fn (array $assignment): string => $assignment['store_id'].':'.$assignment['role_id'])
            ->values()
            ->all();

        $globalRoles = Role::query()->whereIn('id', $globalRoleIds)->with('permissions:id,code')->get()->keyBy('id');

        if ($globalRoles->count() !== count($globalRoleIds)) {
            throw ValidationException::withMessages(['global_role_ids' => [__('admin.security.errors.role_missing')]]);
        }

        foreach ($globalRoles as $role) {
            if (! $role->is_active) {
                throw ValidationException::withMessages(['global_role_ids' => [__('admin.security.errors.role_inactive', ['role' => $role->code])]]);
            }

            if ($role->scope !== 'global') {
                throw ValidationException::withMessages(['global_role_ids' => [__('admin.security.errors.role_store_only', ['role' => $role->code])]]);
            }

            $this->assertActorCanGrantRole($actor, $role, null);
        }

        $storeRoleIds = collect($storeRoles)->pluck('role_id')->unique()->values()->all();
        $storeModels = Role::query()->whereIn('id', $storeRoleIds)->with('permissions:id,code')->get()->keyBy('id');

        if ($storeModels->count() !== count($storeRoleIds)) {
            throw ValidationException::withMessages(['store_roles' => [__('admin.security.errors.role_missing')]]);
        }

        foreach ($storeModels as $role) {
            if (! $role->is_active) {
                throw ValidationException::withMessages(['store_roles' => [__('admin.security.errors.role_inactive', ['role' => $role->code])]]);
            }

            if ($role->scope !== 'store') {
                throw ValidationException::withMessages(['store_roles' => [__('admin.security.errors.role_global_only', ['role' => $role->code])]]);
            }
        }

        $storeIds = collect($storeRoles)->pluck('store_id')->unique()->values()->all();

        $retailStoreCount = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->whereIn('stores.id', $storeIds)
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2C')
            ->distinct()
            ->count('stores.id');

        if ($retailStoreCount !== count($storeIds)) {
            throw ValidationException::withMessages([
                'store_roles' => ['Store-scoped roles can only be assigned to active Retail stores.'],
            ]);
        }

        foreach ($storeRoles as $assignment) {
            $this->assertActorCanGrantRole($actor, $storeModels[(int) $assignment['role_id']], (int) $assignment['store_id']);
        }

        $currentGlobalRoleIds = $target->roles()
            ->pluck('roles.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $currentStorePairs = $target->storeRoleAssignments()
            ->get(['store_id', 'role_id'])
            ->map(static fn ($assignment): string => ((int) $assignment->role_id).':'.((int) $assignment->store_id))
            ->all();

        $newGlobalRoleIds = collect($globalRoleIds)->map(static fn ($value): int => (int) $value)->sort()->values()->all();
        $existingGlobalRoleIds = collect($currentGlobalRoleIds)->map(static fn ($value): int => (int) $value)->sort()->values()->all();
        $newStorePairs = collect($storeRoles)
            ->map(static fn (array $assignment): string => ((int) $assignment['role_id']).':'.((int) $assignment['store_id']))
            ->sort()
            ->values()
            ->all();
        $existingStorePairs = collect($currentStorePairs)->sort()->values()->all();
        $assignmentsChanged = $newGlobalRoleIds !== $existingGlobalRoleIds
            || $newStorePairs !== $existingStorePairs;

        $this->jobRoles->assertAssignmentsAvailable(
            $globalRoles->values(),
            array_map(
                fn (array $assignment): array => [
                    'store_id' => (int) $assignment['store_id'],
                    'role' => $storeModels[(int) $assignment['role_id']],
                ],
                $storeRoles,
            ),
            $currentGlobalRoleIds,
            $currentStorePairs,
        );

        $targetHasSuperAdmin = $target->roles()
            ->where('roles.code', 'SUPER_ADMIN')
            ->where('roles.is_active', true)
            ->exists();
        $newHasSuperAdmin = $globalRoles->contains(static fn (Role $role): bool => $role->code === 'SUPER_ADMIN');

        if ($targetHasSuperAdmin && $newHasSuperAdmin === false && $this->isLastActiveSuperAdmin($target)) {
            throw ValidationException::withMessages(['global_role_ids' => [__('admin.security.errors.last_super_admin_role')]]);
        }

        $before = $this->userSnapshot($target);

        DB::transaction(function () use ($target, $globalRoleIds, $storeRoles, $assignmentsChanged): void {
            $target->roles()->sync($globalRoleIds);
            $target->storeRoleAssignments()->delete();

            if ($assignmentsChanged) {
                DB::table('user_permission_overrides')
                    ->where('user_id', $target->getKey())
                    ->delete();
                DB::table('users')
                    ->where('id', $target->getKey())
                    ->update(['has_permission_overrides' => false]);
            }

            if ($storeRoles !== []) {
                $now = now();
                DB::table('user_store_roles')->insert(array_map(
                    static fn (array $assignment): array => [
                        'user_id' => $target->getKey(),
                        'store_id' => $assignment['store_id'],
                        'role_id' => $assignment['role_id'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    $storeRoles,
                ));
            }
        });

        $fresh = $target->refresh()->load(['roles', 'storeRoleAssignments.role', 'storeRoleAssignments.store']);
        $this->finalizePermissionMutation($actor, $fresh, 'user.roles_changed', $request);
        $fresh = $target->refresh()->load(['roles', 'storeRoleAssignments.role', 'storeRoleAssignments.store']);
        $this->audit->record('user.roles_changed', $actor, $fresh, $before, $this->userSnapshot($fresh), $request);

        return $fresh;
    }

    /**
     * @param  list<int>  $selectedPermissionIds
     */
    public function replaceUserPermissions(
        User $actor,
        User $target,
        array $selectedPermissionIds,
        string $scopeType,
        ?int $storeId,
        Request $request,
    ): User {
        $this->preparePermissionMutation();

        if ($target->hasRole('SUPER_ADMIN')) {
            throw ValidationException::withMessages([
                'permission_ids' => [__('security_permissions.super_admin_permissions_protected')],
            ]);
        }

        if (! in_array($scopeType, ['global', 'store'], true)) {
            throw ValidationException::withMessages([
                'scope_type' => [__('security_permissions.user_permission_scope_invalid')],
            ]);
        }

        if ($scopeType === 'global') {
            $storeId = null;
            if (! $target->roles()
                ->where('roles.is_active', true)
                ->where('roles.scope', 'global')
                ->exists()) {
                throw ValidationException::withMessages([
                    'permission_ids' => [__('security_permissions.user_permission_role_required')],
                ]);
            }
        } else {
            if ($storeId === null || ! $this->userHasStorePermissionContext($target, $storeId)) {
                throw ValidationException::withMessages([
                    'store_id' => [__('security_permissions.user_permission_store_scope_invalid')],
                ]);
            }
        }

        $selectedPermissionIds = array_values(array_unique(array_map('intval', $selectedPermissionIds)));
        $selectedPermissions = Permission::query()
            ->whereIn('id', $selectedPermissionIds)
            ->get(['id', 'code'])
            ->keyBy('id');

        if ($selectedPermissions->count() !== count($selectedPermissionIds)) {
            throw ValidationException::withMessages([
                'permission_ids' => [__('admin.security.errors.permission_missing')],
            ]);
        }

        $this->assertUserPermissionDomain(
            $target,
            $scopeType,
            $selectedPermissions->pluck('code')->map(static fn ($value): string => (string) $value)->values()->all(),
        );

        $actorCodes = $actor->hasRole('SUPER_ADMIN')
            ? collect(array_keys((array) config('permissions.abilities', [])))
            : collect($actor->effectivePermissionCodes($storeId));

        $manageablePermissions = Permission::query()
            ->whereIn('code', $actorCodes->all())
            ->get(['id', 'code'])
            ->keyBy('id');

        $outsideAuthority = $selectedPermissions->keys()->diff($manageablePermissions->keys());
        if ($outsideAuthority->isNotEmpty()) {
            throw ValidationException::withMessages([
                'permission_ids' => [__('admin.security.errors.cannot_grant_higher')],
            ]);
        }

        $scopeKey = $scopeType === 'global' ? 'global' : 'store:'.$storeId;
        $baselineCodes = collect($target->inheritedPermissionCodes($storeId));
        $selectedIdSet = collect($selectedPermissionIds)->flip();
        $before = $this->userSnapshot($target);

        DB::transaction(function () use (
            $target,
            $manageablePermissions,
            $selectedIdSet,
            $baselineCodes,
            $scopeKey,
            $scopeType,
            $storeId,
        ): void {
            DB::table('user_permission_overrides')
                ->where('user_id', $target->getKey())
                ->where('scope_key', $scopeKey)
                ->whereIn('permission_id', $manageablePermissions->keys()->all())
                ->delete();

            $rows = [];
            $now = now();

            foreach ($manageablePermissions as $permission) {
                $selected = $selectedIdSet->has((int) $permission->id);
                $inherited = $baselineCodes->contains((string) $permission->code);

                if ($selected === $inherited) {
                    continue;
                }

                $rows[] = [
                    'user_id' => $target->getKey(),
                    'permission_id' => (int) $permission->id,
                    'scope_key' => $scopeKey,
                    'store_id' => $scopeType === 'store' ? $storeId : null,
                    'effect' => $selected ? 'allow' : 'deny',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows !== []) {
                DB::table('user_permission_overrides')->insert($rows);
            }

            $hasOverrides = DB::table('user_permission_overrides')
                ->where('user_id', $target->getKey())
                ->exists();
            DB::table('users')
                ->where('id', $target->getKey())
                ->update(['has_permission_overrides' => $hasOverrides]);
        });

        $fresh = $target->refresh();
        $this->finalizePermissionMutation($actor, $fresh, 'user.permissions_changed', $request);
        $fresh = $target->refresh();
        $this->audit->record(
            'user.permissions_changed',
            $actor,
            $fresh,
            $before,
            $this->userSnapshot($fresh),
            $request,
        );

        return $fresh;
    }

    /** @param  array{name:string,description:?string,scope:string,is_active:bool,permission_ids:list<int>}  $values */
    public function createRole(User $actor, string $code, array $values, Request $request): Role
    {
        $this->preparePermissionMutation();

        $this->assertSupportedScope($values['scope']);
        $this->assertRolePermissionDomain($code, $values['scope'], $values['permission_ids']);
        $this->assertActorCanGrantPermissions($actor, $values['permission_ids']);

        $role = DB::transaction(function () use ($code, $values): Role {
            $role = Role::query()->create([
                'code' => $code,
                'name' => $values['name'],
                'description' => $values['description'],
                'scope' => $values['scope'],
                'is_system' => false,
                'is_active' => $values['is_active'],
            ]);
            $role->permissions()->sync($values['permission_ids']);

            return $role;
        });

        $fresh = $role->refresh()->load('permissions');
        $this->finalizePermissionMutation($actor, $fresh, 'role.created', $request);
        $fresh = $role->refresh()->load('permissions');
        $this->audit->record('role.created', $actor, $fresh, null, $this->roleSnapshot($fresh), $request);

        return $fresh;
    }

    /** @param  array{name:string,description:?string,scope:string,is_active:bool,permission_ids:list<int>}  $values */
    public function updateRole(User $actor, Role $role, array $values, Request $request): Role
    {
        $this->preparePermissionMutation();

        if ($this->jobRoles->isBuiltIn((string) $role->code)) {
            throw ValidationException::withMessages([
                'role' => [__('job_roles.errors.canonical_locked')],
            ]);
        }

        if ($role->code === 'SUPER_ADMIN') {
            $values['is_active'] = true;
            $values['scope'] = 'global';
            $values['permission_ids'] = Permission::query()->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        }

        if ($role->is_system && $role->scope !== $values['scope']) {
            throw ValidationException::withMessages(['scope' => [__('admin.security.errors.system_scope')]]);
        }

        if ($values['scope'] === 'store' && $role->users()->exists()) {
            throw ValidationException::withMessages(['scope' => [__('admin.security.errors.scope_has_global_assignments')]]);
        }

        if ($values['scope'] === 'global' && $role->storeAssignments()->exists()) {
            throw ValidationException::withMessages(['scope' => [__('admin.security.errors.scope_has_store_assignments')]]);
        }

        $this->assertSupportedScope($values['scope']);
        $this->assertRolePermissionDomain((string) $role->code, $values['scope'], $values['permission_ids']);
        $this->assertActorCanGrantPermissions($actor, $values['permission_ids']);
        $before = $this->roleSnapshot($role->load('permissions'));

        DB::transaction(function () use ($role, $values): void {
            $role->update([
                'name' => $values['name'],
                'description' => $values['description'],
                'scope' => $values['scope'],
                'is_active' => $values['is_active'],
            ]);
            $role->permissions()->sync($values['permission_ids']);
        });

        $fresh = $role->refresh()->load('permissions');
        $this->finalizePermissionMutation($actor, $fresh, 'role.updated', $request);
        $fresh = $role->refresh()->load('permissions');
        $this->audit->record('role.updated', $actor, $fresh, $before, $this->roleSnapshot($fresh), $request);

        return $fresh;
    }

    public function cloneRole(User $actor, Role $source, string $code, string $name, Request $request): Role
    {
        $source->load('permissions:id');

        return $this->createRole($actor, $code, [
            'name' => $name,
            'description' => $source->description,
            'scope' => $source->scope,
            'is_active' => true,
            'permission_ids' => $source->permissions->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
        ], $request);
    }

    public function deleteRole(User $actor, Role $role, Request $request): void
    {
        $this->preparePermissionMutation();

        if ($role->is_system) {
            throw ValidationException::withMessages(['role' => [__('admin.security.errors.system_delete')]]);
        }

        if ($role->users()->exists() || $role->storeAssignments()->exists()) {
            throw ValidationException::withMessages(['role' => [__('admin.security.errors.assigned_role_delete')]]);
        }

        $before = $this->roleSnapshot($role->load('permissions'));
        $this->audit->record('role.deleted', $actor, $role, $before, null, $request);
        $role->delete();
        $this->finalizePermissionMutation($actor, null, 'role.deleted', $request);
    }

    public function isLastActiveSuperAdmin(User $target): bool
    {
        if (! $target->is_active) {
            return false;
        }

        $hasSuperAdmin = $target->roles()
            ->where('roles.code', 'SUPER_ADMIN')
            ->where('roles.is_active', true)
            ->exists();

        if (! $hasSuperAdmin) {
            return false;
        }

        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query
                ->where('roles.code', 'SUPER_ADMIN')
                ->where('roles.is_active', true))
            ->count() <= 1;
    }

    private function assertActorCanGrantRole(User $actor, Role $role, ?int $storeId): void
    {
        if ($actor->hasRole('SUPER_ADMIN')) {
            return;
        }

        if ($role->code === 'SUPER_ADMIN') {
            throw ValidationException::withMessages(['roles' => [__('admin.security.errors.cannot_grant_super_admin')]]);
        }

        $missing = $role->permissions->pluck('code')->diff($actor->effectivePermissionCodes($storeId))->values();

        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['roles' => [__('admin.security.errors.cannot_grant_higher')]]);
        }
    }

    private function assertSupportedScope(string $scope): void
    {
        if (! in_array($scope, ['global', 'store'], true)) {
            throw ValidationException::withMessages([
                'scope' => ['Roles must belong to exactly one domain: Wholesale/global or Retail/store.'],
            ]);
        }
    }

    /** @param list<string> $permissionCodes */
    private function assertUserPermissionDomain(User $target, string $scopeType, array $permissionCodes): void
    {
        $globalRoleCodes = $target->roles()
            ->where('roles.is_active', true)
            ->where('roles.scope', 'global')
            ->pluck('roles.code')
            ->map(static fn ($value): string => (string) $value)
            ->all();

        $retailDomain = $scopeType === 'store'
            || collect($globalRoleCodes)->contains(
                static fn (string $code): bool => $code === 'B2C_DRIVER' || str_starts_with($code, 'RETAIL_'),
            );
        $wholesaleDomain = $scopeType === 'global'
            && collect($globalRoleCodes)->contains(
                static fn (string $code): bool => $code !== 'B2C_DRIVER' && ! str_starts_with($code, 'RETAIL_'),
            );

        $forbidden = collect($permissionCodes)->filter(function (string $code) use ($retailDomain, $wholesaleDomain): bool {
            $allowedByRetail = $retailDomain && ! $this->permissionCrossesRetailBoundary($code);
            $allowedByWholesale = $wholesaleDomain
                && ! str_starts_with($code, 'drivers.b2c.')
                && ! str_starts_with($code, 'deliveries.b2c.');

            return ! $allowedByRetail && ! $allowedByWholesale;
        });

        if ($forbidden->isNotEmpty()) {
            throw ValidationException::withMessages([
                'permission_ids' => [__('security_permissions.user_permission_domain_invalid')],
            ]);
        }
    }

    private function permissionCrossesRetailBoundary(string $code): bool
    {
        return str_starts_with($code, 'b2b.')
            || str_starts_with($code, 'drivers.b2b.')
            || str_starts_with($code, 'deliveries.b2b.')
            || in_array($code, [
                'platform.manage',
                'security.view',
                'roles.manage',
                'users.view',
                'users.roles.manage',
                'users.status.manage',
                'translations.manage',
                'system.update',
                'demo_data.manage',
                'mobile_settings.manage',
                'push_settings.manage',
                'push_settings.test',
            ], true);
    }

    /** @param list<int> $permissionIds */
    private function assertRolePermissionDomain(string $roleCode, string $scope, array $permissionIds): void
    {
        if ($roleCode === 'SUPER_ADMIN') {
            return;
        }

        $codes = Permission::query()->whereIn('id', $permissionIds)->pluck('code');
        $retailRole = $scope === 'store' || $roleCode === 'B2C_DRIVER' || str_starts_with($roleCode, 'RETAIL_');

        if ($retailRole) {
            $forbidden = $codes->filter(fn (string $code): bool => $this->permissionCrossesRetailBoundary($code));
        } else {
            $forbidden = $codes->filter(static fn (string $code): bool => str_starts_with($code, 'drivers.b2c.')
                || str_starts_with($code, 'deliveries.b2c.')
            );
        }

        if ($forbidden->isNotEmpty()) {
            throw ValidationException::withMessages([
                'permission_ids' => ['Selected permissions cross the Wholesale/Retail authorization boundary.'],
            ]);
        }
    }

    /** @param  list<int>  $permissionIds */
    private function assertActorCanGrantPermissions(User $actor, array $permissionIds): void
    {
        if ($actor->hasRole('SUPER_ADMIN')) {
            return;
        }

        $requested = Permission::query()->whereIn('id', $permissionIds)->pluck('code');
        $missing = $requested->diff($actor->effectivePermissionCodes())->values();

        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['permission_ids' => [__('admin.security.errors.cannot_grant_higher')]]);
        }
    }

    private function userHasStorePermissionContext(User $user, int $storeId): bool
    {
        $channel = DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('stores.id', $storeId)
            ->where('stores.is_active', true)
            ->value('store_types.code');

        if (! is_string($channel) || $channel === '') {
            return false;
        }

        $channel = strtolower($channel);
        if ($channel === 'b2c' && $user->storeRoleAssignments()
            ->where('store_id', $storeId)
            ->whereHas('role', fn ($query) => $query
                ->where('is_active', true)
                ->where('scope', 'store'))
            ->exists()) {
            return true;
        }

        $allowedGlobalRoles = array_values((array) config("admin.channels.{$channel}.global_roles", []));

        return $allowedGlobalRoles !== [] && $user->roles()
            ->where('roles.is_active', true)
            ->where('roles.scope', 'global')
            ->whereIn('roles.code', $allowedGlobalRoles)
            ->exists();
    }

    /** @return array<string, mixed> */
    private function userSnapshot(User $user): array
    {
        $user->loadMissing(['roles:id,code', 'storeRoleAssignments.role:id,code']);

        return [
            'id' => (int) $user->getKey(),
            'is_active' => (bool) $user->is_active,
            'deactivated_at' => $user->deactivated_at?->toIso8601String(),
            'deactivation_reason' => $user->deactivation_reason,
            'global_roles' => $user->roles->pluck('code')->sort()->values()->all(),
            'store_roles' => $user->storeRoleAssignments
                ->map(static fn ($assignment): array => [
                    'store_id' => (int) $assignment->store_id,
                    'role' => (string) $assignment->role->code,
                ])
                ->sortBy(static fn (array $assignment): string => sprintf('%020d:%s', $assignment['store_id'], $assignment['role']))
                ->values()
                ->all(),
            'permission_overrides' => DB::table('user_permission_overrides')
                ->join('permissions', 'permissions.id', '=', 'user_permission_overrides.permission_id')
                ->where('user_permission_overrides.user_id', $user->getKey())
                ->orderBy('user_permission_overrides.scope_key')
                ->orderBy('permissions.code')
                ->get([
                    'permissions.code',
                    'user_permission_overrides.scope_key',
                    'user_permission_overrides.effect',
                ])
                ->map(static fn ($row): array => [
                    'code' => (string) $row->code,
                    'scope' => (string) $row->scope_key,
                    'effect' => (string) $row->effect,
                ])
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function roleSnapshot(Role $role): array
    {
        $role->loadMissing('permissions:id,code');

        return [
            'id' => (int) $role->getKey(),
            'code' => (string) $role->code,
            'name' => (string) $role->name,
            'description' => $role->description,
            'scope' => (string) $role->scope,
            'is_system' => (bool) $role->is_system,
            'is_active' => (bool) $role->is_active,
            'permissions' => $role->permissions->pluck('code')->sort()->values()->all(),
        ];
    }
}

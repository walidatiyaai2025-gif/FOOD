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
    public function __construct(private readonly AuditLogger $audit)
    {
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

            if ($role->scope === 'store') {
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

            if ($role->scope === 'global') {
                throw ValidationException::withMessages(['store_roles' => [__('admin.security.errors.role_global_only', ['role' => $role->code])]]);
            }
        }

        $storeIds = collect($storeRoles)->pluck('store_id')->unique()->values()->all();

        if (DB::table('stores')->whereIn('id', $storeIds)->count() !== count($storeIds)) {
            throw ValidationException::withMessages(['store_roles' => [__('admin.security.errors.store_missing')]]);
        }

        foreach ($storeRoles as $assignment) {
            $this->assertActorCanGrantRole($actor, $storeModels[(int) $assignment['role_id']], (int) $assignment['store_id']);
        }

        $targetHasSuperAdmin = $target->roles()
            ->where('roles.code', 'SUPER_ADMIN')
            ->where('roles.is_active', true)
            ->exists();
        $newHasSuperAdmin = $globalRoles->contains(static fn (Role $role): bool => $role->code === 'SUPER_ADMIN');

        if ($targetHasSuperAdmin && ! $newHasSuperAdmin && $this->isLastActiveSuperAdmin($target)) {
            throw ValidationException::withMessages(['global_role_ids' => [__('admin.security.errors.last_super_admin_role')]]);
        }

        $before = $this->userSnapshot($target);

        DB::transaction(function () use ($target, $globalRoleIds, $storeRoles): void {
            $target->roles()->sync($globalRoleIds);
            $target->storeRoleAssignments()->delete();

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
        $this->audit->record('user.roles_changed', $actor, $fresh, $before, $this->userSnapshot($fresh), $request);

        return $fresh;
    }

    /** @param  array{name:string,description:?string,scope:string,is_active:bool,permission_ids:list<int>}  $values */
    public function createRole(User $actor, string $code, array $values, Request $request): Role
    {
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
        $this->audit->record('role.created', $actor, $fresh, null, $this->roleSnapshot($fresh), $request);

        return $fresh;
    }

    /** @param  array{name:string,description:?string,scope:string,is_active:bool,permission_ids:list<int>}  $values */
    public function updateRole(User $actor, Role $role, array $values, Request $request): Role
    {
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
        if ($role->is_system) {
            throw ValidationException::withMessages(['role' => [__('admin.security.errors.system_delete')]]);
        }

        if ($role->users()->exists() || $role->storeAssignments()->exists()) {
            throw ValidationException::withMessages(['role' => [__('admin.security.errors.assigned_role_delete')]]);
        }

        $before = $this->roleSnapshot($role->load('permissions'));
        $this->audit->record('role.deleted', $actor, $role, $before, null, $request);
        $role->delete();
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
                    'role' => (string) $assignment->role?->code,
                ])
                ->sortBy(static fn (array $assignment): string => sprintf('%020d:%s', $assignment['store_id'], $assignment['role']))
                ->values()
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

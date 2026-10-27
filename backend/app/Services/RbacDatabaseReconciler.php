<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class RbacDatabaseReconciler
{
    public function __construct(
        private readonly BuiltInJobRoleCatalog $jobRoles,
    ) {}

    /**
     * Fully rebuild the canonical/system RBAC database state while preserving
     * custom roles, user-role assignments and valid per-user overrides.
     *
     * @return array{permissions:int,roles:int,grants:int,overrides_removed:int}
     */
    public function reconcile(): array
    {
        $abilities = (array) config('permissions.abilities', []);
        if ($abilities === []) {
            throw new RuntimeException('Canonical permission catalog is empty.');
        }

        $canonicalPermissionCodes = array_keys($abilities);

        return DB::transaction(function () use ($abilities, $canonicalPermissionCodes): array {
            $now = now();
            $permissionRows = collect($abilities)
                ->map(static fn (string $name, string $code): array => [
                    'code' => $code,
                    'name' => $name,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->values()
                ->all();

            DB::table('permissions')->upsert(
                $permissionRows,
                ['code'],
                ['name', 'updated_at'],
            );

            $builtInCodes = $this->jobRoles->definitions()
                ->keys()
                ->map(static fn ($code): string => (string) $code)
                ->values()
                ->all();
            $builtInRoleIds = DB::table('roles')
                ->whereIn('code', $builtInCodes)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            // Full reset of canonical role grants. Custom-role grants are never
            // deleted here.
            if ($builtInRoleIds !== []) {
                DB::table('permission_role')
                    ->whereIn('role_id', $builtInRoleIds)
                    ->delete();
            }

            $catalog = $this->jobRoles->reconcile();
            $overridesRemoved = $this->cleanUserPermissionOverrides($canonicalPermissionCodes);

            $this->assertCanonicalIntegrity($canonicalPermissionCodes);

            return [
                'permissions' => count($permissionRows),
                'roles' => (int) $catalog['roles'],
                'grants' => (int) $catalog['permissions'],
                'overrides_removed' => $overridesRemoved,
            ];
        });
    }

    /**
     * @param  list<string>  $canonicalPermissionCodes
     */
    private function cleanUserPermissionOverrides(array $canonicalPermissionCodes): int
    {
        if (! Schema::hasTable('user_permission_overrides')) {
            return 0;
        }

        $canonicalPermissionIds = DB::table('permissions')
            ->whereIn('code', $canonicalPermissionCodes)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $removed = DB::table('user_permission_overrides')
            ->whereNotIn('permission_id', $canonicalPermissionIds)
            ->delete();

        $removed += DB::table('user_permission_overrides')
            ->whereNotIn('effect', ['allow', 'deny'])
            ->delete();

        $removed += DB::table('user_permission_overrides')
            ->where('scope_key', 'global')
            ->whereNotNull('store_id')
            ->delete();

        $rows = DB::table('user_permission_overrides')
            ->where('scope_key', '!=', 'global')
            ->get(['id', 'scope_key', 'store_id']);

        foreach ($rows as $row) {
            $storeId = is_numeric($row->store_id) ? (int) $row->store_id : null;
            $valid = $storeId !== null
                && $storeId > 0
                && $row->scope_key === 'store:'.$storeId;

            if (! $valid) {
                $removed += DB::table('user_permission_overrides')
                    ->where('id', (int) $row->id)
                    ->delete();
            }
        }

        return $removed;
    }

    /**
     * @param  list<string>  $canonicalPermissionCodes
     */
    private function assertCanonicalIntegrity(array $canonicalPermissionCodes): void
    {
        $persistedCodes = DB::table('permissions')
            ->whereIn('code', $canonicalPermissionCodes)
            ->pluck('code')
            ->map(static fn ($code): string => (string) $code)
            ->all();

        $missingPermissions = array_values(array_diff($canonicalPermissionCodes, $persistedCodes));
        if ($missingPermissions !== []) {
            throw new RuntimeException(
                'RBAC reconciliation is missing canonical permissions: '.implode(', ', $missingPermissions),
            );
        }

        $allPermissionCodes = array_keys((array) config('permissions.abilities', []));

        foreach ($this->jobRoles->definitions() as $code => $definition) {
            $definitionPermissions = array_values(array_map(
                static fn ($permission): string => (string) $permission,
                (array) ($definition['permissions'] ?? []),
            ));
            $expected = $definitionPermissions === ['*']
                ? $allPermissionCodes
                : $definitionPermissions;
            sort($expected);

            $actual = DB::table('permission_role')
                ->join('roles', 'roles.id', '=', 'permission_role.role_id')
                ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->where('roles.code', $code)
                ->pluck('permissions.code')
                ->map(static fn ($permission): string => (string) $permission)
                ->sort()
                ->values()
                ->all();

            if ($expected !== $actual) {
                throw new RuntimeException('RBAC reconciliation failed for built-in role ['.$code.'].');
            }
        }
    }
}

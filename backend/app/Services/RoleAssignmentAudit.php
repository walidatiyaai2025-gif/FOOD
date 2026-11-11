<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Read-only source-to-current role assignment inventory (T015R04).
 *
 * These are stored links, not effective permissions. Authorization continues
 * through the existing server RBAC, store/channel scope, readiness and SoD.
 */
final class RoleAssignmentAudit
{
    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        $globalByRole = DB::table('role_user')
            ->select('role_id')
            ->selectRaw('COUNT(*) AS assignments')
            ->groupBy('role_id')
            ->pluck('assignments', 'role_id');

        $storeByRole = DB::table('user_store_roles')
            ->select('role_id')
            ->selectRaw('COUNT(*) AS assignments')
            ->groupBy('role_id')
            ->pluck('assignments', 'role_id');

        $roleRows = Role::query()
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'scope'])
            ->map(static fn (Role $role): array => [
                'code' => (string) $role->code,
                'name' => (string) $role->name,
                'global' => (int) $globalByRole->get($role->id, 0),
                'store' => (int) $storeByRole->get($role->id, 0),
            ])
            ->filter(static fn (array $row): bool => $row['global'] + $row['store'] > 0)
            ->values()
            ->all();

        // The grouped query reads names for a Super Admin only; never expose
        // user identifiers, raw session tokens or individual assignments here.
        $storeRows = DB::table('user_store_roles')
            ->join('stores', 'stores.id', '=', 'user_store_roles.store_id')
            ->select('stores.id', 'stores.name', 'stores.is_active')
            ->selectRaw('COUNT(*) AS assignments')
            ->groupBy('stores.id', 'stores.name', 'stores.is_active')
            ->orderBy('stores.name')
            ->get()
            ->map(static fn ($row): array => [
                'name' => (string) $row->name,
                'assignments' => (int) $row->assignments,
                'active' => (bool) $row->is_active,
            ])
            ->all();

        $inactiveRoleLinks = static function (string $table): int {
            return (int) DB::table($table)
                ->join('roles', 'roles.id', '=', $table.'.role_id')
                ->where(static fn ($query) => $query
                    ->where('roles.is_active', false)
                    ->orWhereNotNull('roles.archived_at'))
                ->count();
        };

        return [
            'global_assignments' => (int) DB::table('role_user')->count(),
            'store_assignments' => (int) DB::table('user_store_roles')->count(),
            'assigned_users' => User::query()
                ->whereHas('roles')
                ->orWhereHas('storeRoleAssignments')
                ->count(),
            'distinct_stores' => count($storeRows),
            'invalid_global_scope' => (int) DB::table('role_user')
                ->join('roles', 'roles.id', '=', 'role_user.role_id')
                ->where('roles.scope', 'store')
                ->count(),
            'invalid_store_scope' => (int) DB::table('user_store_roles')
                ->join('roles', 'roles.id', '=', 'user_store_roles.role_id')
                ->where('roles.scope', 'global')
                ->count(),
            'inactive_role_links' => $inactiveRoleLinks('role_user') + $inactiveRoleLinks('user_store_roles'),
            'inactive_store_links' => (int) DB::table('user_store_roles')
                ->join('stores', 'stores.id', '=', 'user_store_roles.store_id')
                ->where('stores.is_active', false)
                ->count(),
            'roles' => $roleRows,
            'stores' => $storeRows,
        ];
    }
}

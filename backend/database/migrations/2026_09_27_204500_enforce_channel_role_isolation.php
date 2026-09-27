<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, array{name:string,permissions:list<string>}> */
    private const RETAIL_ROLES = [
        'RETAIL_OPERATIONS' => [
            'name' => 'Retail Operations',
            'permissions' => [
                'orders.view', 'orders.edit', 'orders.approve', 'orders.manage',
                'drivers.b2c.view', 'drivers.b2c.manage', 'reports.view',
            ],
        ],
        'RETAIL_INVENTORY' => [
            'name' => 'Retail Inventory',
            'permissions' => [
                'catalog.view', 'catalog.create', 'catalog.edit', 'catalog.manage', 'lookups.view',
                'inventory.view', 'inventory.adjust', 'inventory.manage',
            ],
        ],
        'RETAIL_FINANCE' => [
            'name' => 'Retail Finance',
            'permissions' => ['finance.view', 'finance.manage', 'reports.view', 'reports.export'],
        ],
        'RETAIL_CUSTOMER_SUPPORT' => [
            'name' => 'Retail Customer Support',
            'permissions' => [
                'support.view', 'support.manage', 'customers.view', 'customers.edit',
                'customers.manage', 'orders.view', 'orders.manage',
            ],
        ],
    ];

    /** @var array<string, string> */
    private const WHOLESALE_TO_RETAIL = [
        'OPERATIONS' => 'RETAIL_OPERATIONS',
        'INVENTORY' => 'RETAIL_INVENTORY',
        'FINANCE' => 'RETAIL_FINANCE',
        'CUSTOMER_SUPPORT' => 'RETAIL_CUSTOMER_SUPPORT',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $now = now();

            foreach (self::RETAIL_ROLES as $code => $definition) {
                DB::table('roles')->updateOrInsert(
                    ['code' => $code],
                    [
                        'name' => $definition['name'],
                        'description' => 'Retail store-scoped operational role.',
                        'scope' => 'store',
                        'is_system' => true,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );

                $roleId = (int) DB::table('roles')->where('code', $code)->value('id');
                $permissionIds = DB::table('permissions')
                    ->whereIn('code', $definition['permissions'])
                    ->pluck('id');

                DB::table('permission_role')->where('role_id', $roleId)->delete();
                foreach ($permissionIds as $permissionId) {
                    DB::table('permission_role')->updateOrInsert([
                        'permission_id' => (int) $permissionId,
                        'role_id' => $roleId,
                    ]);
                }
            }

            $retailStoreIds = DB::table('stores')
                ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
                ->where('store_types.code', 'B2C')
                ->pluck('stores.id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $wholesaleStoreIds = DB::table('stores')
                ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
                ->where('store_types.code', 'B2B')
                ->pluck('stores.id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            foreach (self::WHOLESALE_TO_RETAIL as $wholesaleCode => $retailCode) {
                $wholesaleRoleId = DB::table('roles')->where('code', $wholesaleCode)->value('id');
                $retailRoleId = DB::table('roles')->where('code', $retailCode)->value('id');

                if ($wholesaleRoleId === null || $retailRoleId === null) {
                    continue;
                }

                $retailAssignments = DB::table('user_store_roles')
                    ->where('role_id', $wholesaleRoleId)
                    ->whereIn('store_id', $retailStoreIds)
                    ->get(['id', 'user_id', 'store_id']);

                foreach ($retailAssignments as $assignment) {
                    DB::table('user_store_roles')->updateOrInsert(
                        [
                            'user_id' => (int) $assignment->user_id,
                            'store_id' => (int) $assignment->store_id,
                            'role_id' => (int) $retailRoleId,
                        ],
                        ['created_at' => $now, 'updated_at' => $now],
                    );
                    DB::table('user_store_roles')->where('id', $assignment->id)->delete();
                }

                $wholesaleAssignments = DB::table('user_store_roles')
                    ->where('role_id', $wholesaleRoleId)
                    ->whereIn('store_id', $wholesaleStoreIds)
                    ->get(['id', 'user_id']);

                foreach ($wholesaleAssignments as $assignment) {
                    DB::table('role_user')->updateOrInsert([
                        'role_id' => (int) $wholesaleRoleId,
                        'user_id' => (int) $assignment->user_id,
                    ]);
                    DB::table('user_store_roles')->where('id', $assignment->id)->delete();
                }
            }

            $roleUpdates = [
                'B2C_STORE_ADMIN' => ['name' => 'Retail Store Admin', 'scope' => 'store'],
                'OPERATIONS' => ['name' => 'B2B Operations', 'scope' => 'global'],
                'INVENTORY' => ['name' => 'B2B Inventory', 'scope' => 'global'],
                'FINANCE' => ['name' => 'B2B Finance', 'scope' => 'global'],
                'CUSTOMER_SUPPORT' => ['name' => 'B2B Customer Support', 'scope' => 'global'],
                'B2C_DRIVER' => ['name' => 'Retail Driver', 'scope' => 'global'],
                'B2B_DRIVER' => ['name' => 'B2B Driver', 'scope' => 'global'],
            ];

            foreach ($roleUpdates as $code => $values) {
                DB::table('roles')->where('code', $code)->update([
                    ...$values,
                    'updated_at' => $now,
                ]);
            }

            $wholesaleGrants = [
                'OPERATIONS' => ['stores.view'],
                'INVENTORY' => ['stores.view'],
                'FINANCE' => ['stores.view'],
                'CUSTOMER_SUPPORT' => ['stores.view', 'b2b.accounts.view', 'b2b.accounts.manage'],
            ];

            foreach ($wholesaleGrants as $roleCode => $permissionCodes) {
                $roleId = DB::table('roles')->where('code', $roleCode)->value('id');
                if ($roleId === null) {
                    continue;
                }

                foreach (DB::table('permissions')->whereIn('code', $permissionCodes)->pluck('id') as $permissionId) {
                    DB::table('permission_role')->updateOrInsert([
                        'permission_id' => (int) $permissionId,
                        'role_id' => (int) $roleId,
                    ]);
                }
            }

            // Legacy custom "both" roles are collapsed to one domain. Retail assignments win.
            foreach (DB::table('roles')->where('scope', 'both')->get(['id']) as $role) {
                $hasRetailAssignment = DB::table('user_store_roles')
                    ->where('role_id', $role->id)
                    ->whereIn('store_id', $retailStoreIds)
                    ->exists();

                DB::table('roles')->where('id', $role->id)->update([
                    'scope' => $hasRetailAssignment ? 'store' : 'global',
                    'updated_at' => $now,
                ]);
            }

            // user_store_roles is a Retail-only association. Invalid historical assignments
            // are removed from authorization state rather than remaining as a dormant bypass.
            if ($wholesaleStoreIds !== []) {
                DB::table('user_store_roles')->whereIn('store_id', $wholesaleStoreIds)->delete();
            }

            DB::table('user_store_roles')
                ->whereIn('role_id', DB::table('roles')->where('scope', '!=', 'store')->select('id'))
                ->delete();

            // Wholesale global Operations must not carry Retail-driver administration.
            $operationsId = DB::table('roles')->where('code', 'OPERATIONS')->value('id');
            if ($operationsId !== null) {
                $retailDriverPermissions = DB::table('permissions')
                    ->whereIn('code', ['drivers.b2c.view', 'drivers.b2c.manage', 'deliveries.b2c.execute'])
                    ->pluck('id');
                DB::table('permission_role')
                    ->where('role_id', $operationsId)
                    ->whereIn('permission_id', $retailDriverPermissions)
                    ->delete();
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $now = now();
            $retailStoreIds = DB::table('stores')
                ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
                ->where('store_types.code', 'B2C')
                ->pluck('stores.id')
                ->all();

            foreach (self::WHOLESALE_TO_RETAIL as $wholesaleCode => $retailCode) {
                $wholesaleRoleId = DB::table('roles')->where('code', $wholesaleCode)->value('id');
                $retailRoleId = DB::table('roles')->where('code', $retailCode)->value('id');

                if ($wholesaleRoleId === null || $retailRoleId === null) {
                    continue;
                }

                foreach (DB::table('user_store_roles')
                    ->where('role_id', $retailRoleId)
                    ->whereIn('store_id', $retailStoreIds)
                    ->get(['id', 'user_id', 'store_id']) as $assignment) {
                    DB::table('user_store_roles')->updateOrInsert(
                        [
                            'user_id' => (int) $assignment->user_id,
                            'store_id' => (int) $assignment->store_id,
                            'role_id' => (int) $wholesaleRoleId,
                        ],
                        ['created_at' => $now, 'updated_at' => $now],
                    );
                    DB::table('user_store_roles')->where('id', $assignment->id)->delete();
                }
            }

            DB::table('roles')->where('code', 'B2C_STORE_ADMIN')->update(['name' => 'B2C Store Admin', 'updated_at' => $now]);
            DB::table('roles')->where('code', 'B2C_DRIVER')->update(['name' => 'B2C Driver', 'updated_at' => $now]);

            foreach (array_keys(self::WHOLESALE_TO_RETAIL) as $code) {
                DB::table('roles')->where('code', $code)->update(['scope' => 'both', 'updated_at' => $now]);
            }

            $retailRoleIds = DB::table('roles')->whereIn('code', array_keys(self::RETAIL_ROLES))->pluck('id');
            DB::table('permission_role')->whereIn('role_id', $retailRoleIds)->delete();
            DB::table('roles')->whereIn('id', $retailRoleIds)->delete();
        });
    }
};

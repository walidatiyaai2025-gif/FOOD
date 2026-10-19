<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string,list<string>> */
    private const GRANTS = [
        'CUSTOMER_SERVICE_MANAGER' => [
            'customer_service.team.manage',
            'orders.view',
            'orders.create',
            'orders.edit',
            'orders.approve',
            'orders.manage',
            'orders.dispatch',
            'finance.view',
        ],
        'CUSTOMER_SERVICE_SUPERVISOR' => [
            'orders.view',
            'orders.create',
            'orders.manage',
            'orders.dispatch',
            'finance.view',
        ],
        'CUSTOMER_SERVICE_AGENT' => [
            'orders.view',
            'orders.create',
            'orders.manage',
            'orders.dispatch',
            'finance.view',
        ],
        'CUSTOMER_SUPPORT' => [
            'orders.view',
            'orders.manage',
            'orders.dispatch',
            'finance.view',
        ],
        'RETAIL_CUSTOMER_SUPPORT' => [
            'orders.view',
            'orders.manage',
            'orders.dispatch',
            'finance.view',
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('roles')
            || ! Schema::hasTable('permissions')
            || ! Schema::hasTable('permission_role')) {
            return;
        }

        $roleIds = DB::table('roles')
            ->whereIn('code', array_keys(self::GRANTS))
            ->pluck('id', 'code');

        $permissionIds = DB::table('permissions')
            ->whereIn('code', collect(self::GRANTS)->flatten()->unique()->values()->all())
            ->pluck('id', 'code');

        $rows = [];
        foreach (self::GRANTS as $roleCode => $permissionCodes) {
            $roleId = $roleIds->get($roleCode);
            if ($roleId === null) {
                continue;
            }

            foreach ($permissionCodes as $permissionCode) {
                $permissionId = $permissionIds->get($permissionCode);
                if ($permissionId === null) {
                    continue;
                }

                $rows[] = [
                    'role_id' => (int) $roleId,
                    'permission_id' => (int) $permissionId,
                ];
            }
        }

        if ($rows !== []) {
            DB::table('permission_role')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        // Permission reconciliation is intentionally monotonic. Removing these
        // grants on rollback could revoke capabilities that predated this fix.
    }
};

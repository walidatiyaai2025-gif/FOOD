<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string,list<string>> */
    private const GRANTS = [
        'CUSTOMER_SERVICE_MANAGER' => ['finance.view'],
        'CUSTOMER_SERVICE_SUPERVISOR' => ['orders.manage', 'orders.dispatch', 'finance.view'],
        'CUSTOMER_SERVICE_AGENT' => ['orders.manage', 'orders.dispatch', 'finance.view'],
        'CUSTOMER_SUPPORT' => ['finance.view'],
        'RETAIL_CUSTOMER_SUPPORT' => ['finance.view'],
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
        $permissionCodes = collect(self::GRANTS)->flatten()->unique()->values()->all();
        $permissionIds = DB::table('permissions')
            ->whereIn('code', $permissionCodes)
            ->pluck('id', 'code');

        $rows = [];
        foreach (self::GRANTS as $roleCode => $codes) {
            $roleId = $roleIds->get($roleCode);
            if ($roleId === null) {
                continue;
            }

            foreach ($codes as $permissionCode) {
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
        if (! Schema::hasTable('roles')
            || ! Schema::hasTable('permissions')
            || ! Schema::hasTable('permission_role')) {
            return;
        }

        foreach (self::GRANTS as $roleCode => $codes) {
            $roleId = DB::table('roles')->where('code', $roleCode)->value('id');
            if ($roleId === null) {
                continue;
            }

            $permissionIds = DB::table('permissions')
                ->whereIn('code', $codes)
                ->pluck('id');

            if ($permissionIds->isNotEmpty()) {
                DB::table('permission_role')
                    ->where('role_id', (int) $roleId)
                    ->whereIn('permission_id', $permissionIds->all())
                    ->delete();
            }
        }
    }
};

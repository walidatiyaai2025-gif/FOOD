<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $permissionId = DB::table('permissions')
                ->where('code', 'orders.dispatch')
                ->value('id');

            if ($permissionId === null) {
                return;
            }

            $roleIds = DB::table('roles')
                ->whereIn('code', [
                    'CUSTOMER_SERVICE_MANAGER',
                    'CUSTOMER_SERVICE_SUPERVISOR',
                    'CUSTOMER_SERVICE_AGENT',
                ])
                ->pluck('id');

            foreach ($roleIds as $roleId) {
                DB::table('permission_role')->updateOrInsert([
                    'permission_id' => (int) $permissionId,
                    'role_id' => (int) $roleId,
                ]);
            }
        });
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')
            ->where('code', 'orders.dispatch')
            ->value('id');

        if ($permissionId === null) {
            return;
        }

        $roleIds = DB::table('roles')
            ->whereIn('code', [
                'CUSTOMER_SERVICE_MANAGER',
                'CUSTOMER_SERVICE_SUPERVISOR',
                'CUSTOMER_SERVICE_AGENT',
            ])
            ->pluck('id');

        DB::table('permission_role')
            ->where('permission_id', (int) $permissionId)
            ->whereIn('role_id', $roleIds->all())
            ->delete();
    }
};

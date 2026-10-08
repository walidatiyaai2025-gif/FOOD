<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $now = now();
            $abilities = (array) config('permissions.abilities', []);
            $matrix = (array) config('permissions.roles', []);

            if (array_key_exists('orders.dispatch', $abilities)) {
                DB::table('permissions')->upsert([[
                    'code' => 'orders.dispatch',
                    'name' => (string) $abilities['orders.dispatch'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]], ['code'], ['name', 'updated_at']);
            }

            $permissionId = DB::table('permissions')->where('code', 'orders.dispatch')->value('id');
            if ($permissionId === null) {
                return;
            }

            foreach (['B2B_ADMIN', 'B2C_STORE_ADMIN', 'OPERATIONS', 'CUSTOMER_SUPPORT', 'RETAIL_OPERATIONS', 'RETAIL_CUSTOMER_SUPPORT'] as $roleCode) {
                $configured = array_values((array) ($matrix[$roleCode] ?? []));
                if (! in_array('orders.dispatch', $configured, true) && ! in_array('*', $configured, true)) {
                    continue;
                }

                $roleId = DB::table('roles')->where('code', $roleCode)->value('id');
                if ($roleId === null) {
                    continue;
                }

                DB::table('permission_role')->updateOrInsert([
                    'permission_id' => (int) $permissionId,
                    'role_id' => (int) $roleId,
                ]);
            }
        });
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('code', 'orders.dispatch')->value('id');
        if ($permissionId === null) {
            return;
        }

        DB::table('permission_role')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};

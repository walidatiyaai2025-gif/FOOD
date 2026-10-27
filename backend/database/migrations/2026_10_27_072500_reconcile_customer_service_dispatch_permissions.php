<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const CUSTOMER_SERVICE_ROLES = [
        'CUSTOMER_SERVICE_MANAGER',
        'CUSTOMER_SERVICE_SUPERVISOR',
        'CUSTOMER_SERVICE_AGENT',
    ];

    public function up(): void
    {
        if (
            ! Schema::hasTable('roles')
            || ! Schema::hasTable('permissions')
            || ! Schema::hasTable('permission_role')
        ) {
            return;
        }

        $permissionName = (string) config(
            'permissions.abilities.orders.dispatch',
            'Assign, reassign and unassign order execution to authorized Drivers or Vans',
        );

        DB::table('permissions')->upsert([[
            'code' => 'orders.dispatch',
            'name' => $permissionName,
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['code'], ['name', 'updated_at']);

        $permissionId = DB::table('permissions')
            ->where('code', 'orders.dispatch')
            ->value('id');

        if ($permissionId === null) {
            return;
        }

        $roleIds = DB::table('roles')
            ->whereIn('code', self::CUSTOMER_SERVICE_ROLES)
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            DB::table('permission_role')->updateOrInsert([
                'permission_id' => (int) $permissionId,
                'role_id' => (int) $roleId,
            ]);
        }
    }

    public function down(): void
    {
        // This migration reconciles canonical Customer Service RBAC on upgraded
        // installations. Rolling it back must not silently remove valid access.
    }
};

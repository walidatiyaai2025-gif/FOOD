<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $roleId = DB::table('roles')->where('code', 'B2C_STORE_ADMIN')->value('id');
        if ($roleId === null) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('code', ['customers.delete', 'settings.view', 'settings.manage'])
            ->pluck('id');

        foreach ($permissionIds as $permissionId) {
            DB::table('permission_role')->updateOrInsert([
                'permission_id' => $permissionId,
                'role_id' => $roleId,
            ]);
        }
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('code', 'B2C_STORE_ADMIN')->value('id');
        if ($roleId === null) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('code', ['customers.delete', 'settings.view', 'settings.manage'])
            ->pluck('id');

        DB::table('permission_role')
            ->where('role_id', $roleId)
            ->whereIn('permission_id', $permissionIds)
            ->delete();
    }
};

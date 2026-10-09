<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions') || ! Schema::hasTable('permission_role')) {
            return;
        }

        $now = now();

        DB::table('roles')->updateOrInsert(
            ['code' => 'VAN_OPERATOR'],
            [
                'name' => 'Van App Operator',
                'scope' => 'global',
                'is_system' => true,
                'is_active' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        DB::table('permissions')->updateOrInsert(
            ['code' => 'van.login'],
            [
                'name' => 'Use the Van application runtime',
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        $roleId = DB::table('roles')->where('code', 'VAN_OPERATOR')->value('id');
        $permissionId = DB::table('permissions')->where('code', 'van.login')->value('id');

        if ($roleId !== null && $permissionId !== null) {
            DB::table('permission_role')->updateOrInsert([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    public function down(): void
    {
        // Reference-data repair is intentionally irreversible.
    }
};

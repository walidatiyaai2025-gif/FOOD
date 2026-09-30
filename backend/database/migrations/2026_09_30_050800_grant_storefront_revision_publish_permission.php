<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $now = now();
            DB::table('permissions')->updateOrInsert(
                ['code' => 'app_preview.publish'],
                [
                    'name' => 'Publish and roll back scoped Customer App storefront revisions',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );

            $permissionId = DB::table('permissions')->where('code', 'app_preview.publish')->value('id');
            $roleIds = DB::table('roles')
                ->whereIn('code', ['SUPER_ADMIN', 'B2B_ADMIN', 'B2C_STORE_ADMIN'])
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
        $permissionId = DB::table('permissions')->where('code', 'app_preview.publish')->value('id');
        if ($permissionId !== null) {
            DB::table('permission_role')->where('permission_id', (int) $permissionId)->delete();
            DB::table('permissions')->where('id', (int) $permissionId)->delete();
        }
    }
};

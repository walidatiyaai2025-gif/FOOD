<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $now = now();
            $pairs = [
                'B2B_DRIVER' => [
                    'code' => 'deliveries.b2b.execute',
                    'name' => 'Execute B2B delivery assignments',
                ],
                'B2C_DRIVER' => [
                    'code' => 'deliveries.b2c.execute',
                    'name' => 'Execute Retail delivery assignments',
                ],
            ];

            foreach ($pairs as $roleCode => $permission) {
                $permissionId = DB::table('permissions')
                    ->where('code', $permission['code'])
                    ->value('id');

                if ($permissionId === null) {
                    $permissionId = DB::table('permissions')->insertGetId([
                        'code' => $permission['code'],
                        'name' => $permission['name'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } else {
                    DB::table('permissions')
                        ->where('id', $permissionId)
                        ->update([
                            'name' => $permission['name'],
                            'updated_at' => $now,
                        ]);
                }

                $roleId = DB::table('roles')->where('code', $roleCode)->value('id');

                if ($roleId !== null) {
                    DB::table('permission_role')->insertOrIgnore([
                        'permission_id' => (int) $permissionId,
                        'role_id' => (int) $roleId,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Production reconciliation: do not remove execution permissions on rollback.
    }
};

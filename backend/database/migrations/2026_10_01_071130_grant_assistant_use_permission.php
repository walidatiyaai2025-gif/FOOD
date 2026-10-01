<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSION = 'assistant.use';

    private const GRANTED_ROLES = [
        'SUPER_ADMIN',
        'B2B_ADMIN',
        'B2C_STORE_ADMIN',
        'OPERATIONS',
        'INVENTORY',
        'FINANCE',
        'CUSTOMER_SUPPORT',
        'RETAIL_OPERATIONS',
        'RETAIL_INVENTORY',
        'RETAIL_FINANCE',
        'RETAIL_CUSTOMER_SUPPORT',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $now = now();

            DB::table('permissions')->upsert([[
                'code' => self::PERMISSION,
                'name' => 'Use the read-only FOODEX management Assistant within authorized business scope',
                'created_at' => $now,
                'updated_at' => $now,
            ]], ['code'], ['name', 'updated_at']);

            $permissionId = DB::table('permissions')->where('code', self::PERMISSION)->value('id');
            if ($permissionId === null) {
                return;
            }

            $roleIds = DB::table('roles')->whereIn('code', self::GRANTED_ROLES)->pluck('id');
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
        $permissionId = DB::table('permissions')->where('code', self::PERMISSION)->value('id');

        if ($permissionId !== null) {
            DB::table('permission_role')->where('permission_id', (int) $permissionId)->delete();
            DB::table('permissions')->where('id', (int) $permissionId)->delete();
        }
    }
};

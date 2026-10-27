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

            $roles = DB::table('roles')
                ->whereIn('code', [
                    'CUSTOMER_SERVICE_MANAGER',
                    'CUSTOMER_SERVICE_SUPERVISOR',
                    'CUSTOMER_SERVICE_AGENT',
                ])
                ->get(['id', 'code']);

            foreach ($roles as $role) {
                DB::table('roles')
                    ->where('id', (int) $role->id)
                    ->update([
                        'scope' => 'global',
                        'is_system' => true,
                        'is_active' => true,
                        'updated_at' => now(),
                    ]);

                DB::table('permission_role')->updateOrInsert([
                    'permission_id' => (int) $permissionId,
                    'role_id' => (int) $role->id,
                ]);
            }
        });
    }

    public function down(): void
    {
        // Deliberately irreversible: this migration repairs canonical built-in
        // role metadata and a permission already required by prior releases.
    }
};

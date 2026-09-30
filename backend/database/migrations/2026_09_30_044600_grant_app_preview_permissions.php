<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PREVIEW_ABILITIES = [
        'app_preview.view' => 'Open the real Customer and Driver application preview runtime',
        'app_preview.impersonate_customer' => 'Preview the application as an authorized customer identity',
        'app_preview.impersonate_driver' => 'Preview the application as an authorized driver identity',
    ];

    private const GRANTED_ROLES = [
        'SUPER_ADMIN',
        'B2B_ADMIN',
        'B2C_STORE_ADMIN',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $now = now();

            DB::table('permissions')->upsert(
                collect(self::PREVIEW_ABILITIES)->map(fn (string $name, string $code): array => [
                    'code' => $code,
                    'name' => $name,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->values()->all(),
                ['code'],
                ['name', 'updated_at'],
            );

            $permissionIds = DB::table('permissions')
                ->whereIn('code', array_keys(self::PREVIEW_ABILITIES))
                ->pluck('id');
            $roleIds = DB::table('roles')
                ->whereIn('code', self::GRANTED_ROLES)
                ->pluck('id');

            foreach ($roleIds as $roleId) {
                foreach ($permissionIds as $permissionId) {
                    DB::table('permission_role')->updateOrInsert([
                        'permission_id' => (int) $permissionId,
                        'role_id' => (int) $roleId,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('code', array_keys(self::PREVIEW_ABILITIES))
            ->pluck('id');

        if ($permissionIds->isNotEmpty()) {
            DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }
    }
};

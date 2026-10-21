<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const COMMERCIAL_GRANTS = [
        'catalog.view',
        'catalog.create',
        'catalog.edit',
        'catalog.manage',
        'inventory.view',
        'inventory.adjust',
        'inventory.manage',
        'promotions.view',
        'promotions.manage',
        'notifications.view',
        'notifications.manage',
        'coupons.view',
        'coupons.manage',
        'live_ads.view',
        'live_ads.manage',
        'whatsapp.campaigns.view',
        'whatsapp.campaigns.manage',
        'whatsapp.campaigns.send',
        'reports.export',
    ];

    /** @var list<string> */
    private const COMMERCIAL_ROLES = [
        'CUSTOMER_SERVICE_MANAGER',
        'CUSTOMER_SERVICE_SUPERVISOR',
    ];

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

        $commercialRoleIds = DB::table('roles')
            ->whereIn('code', self::COMMERCIAL_ROLES)
            ->pluck('id');

        if ($commercialRoleIds->isNotEmpty()) {
            $permissionIds = DB::table('permissions')
                ->whereIn('code', self::COMMERCIAL_GRANTS)
                ->pluck('id');

            foreach ($commercialRoleIds as $roleId) {
                foreach ($permissionIds as $permissionId) {
                    DB::table('permission_role')->updateOrInsert([
                        'permission_id' => (int) $permissionId,
                        'role_id' => (int) $roleId,
                    ]);
                }
            }
        }

        $trackingPermissionId = DB::table('permissions')
            ->where('code', 'drivers.tracking.view')
            ->value('id');

        if ($trackingPermissionId === null) {
            return;
        }

        $customerServiceRoleIds = DB::table('roles')
            ->whereIn('code', self::CUSTOMER_SERVICE_ROLES)
            ->pluck('id');

        DB::table('permission_role')
            ->where('permission_id', (int) $trackingPermissionId)
            ->whereIn('role_id', $customerServiceRoleIds)
            ->delete();
    }

    public function down(): void
    {
        if (
            ! Schema::hasTable('roles')
            || ! Schema::hasTable('permissions')
            || ! Schema::hasTable('permission_role')
        ) {
            return;
        }

        $commercialRoleIds = DB::table('roles')
            ->whereIn('code', self::COMMERCIAL_ROLES)
            ->pluck('id');

        if ($commercialRoleIds->isNotEmpty()) {
            $permissionIds = DB::table('permissions')
                ->whereIn('code', self::COMMERCIAL_GRANTS)
                ->pluck('id');

            DB::table('permission_role')
                ->whereIn('role_id', $commercialRoleIds)
                ->whereIn('permission_id', $permissionIds)
                ->delete();
        }

        $trackingPermissionId = DB::table('permissions')
            ->where('code', 'drivers.tracking.view')
            ->value('id');

        if ($trackingPermissionId === null) {
            return;
        }

        $roleIds = DB::table('roles')
            ->whereIn('code', self::CUSTOMER_SERVICE_ROLES)
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            DB::table('permission_role')->updateOrInsert([
                'permission_id' => (int) $trackingPermissionId,
                'role_id' => (int) $roleId,
            ]);
        }
    }
};

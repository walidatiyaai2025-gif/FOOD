<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BuiltInRoleUpgradeReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_upgraded_b2b_admin_rbac_is_reconciled_for_every_b2b_module(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $typeId = (int) DB::table('store_types')->where('code', 'B2B')->value('id');
        DB::table('stores')->insert([
            'store_type_id' => $typeId,
            'code' => 'UPGRADE-B2B',
            'name' => 'Upgrade Wholesale',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $user = User::query()->create([
            'name' => 'Upgrade B2B Admin',
            'email' => 'upgrade-b2b-admin@example.test',
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $user->roles()->attach($role);

        DB::table('roles')->where('id', $role->id)->update([
            'scope' => 'both',
            'updated_at' => now(),
        ]);
        DB::table('permission_role')->where('role_id', $role->id)->delete();

        $this->actingAs($user)->get('/admin/b2b/orders')->assertForbidden();

        $migration = require database_path('migrations/2026_09_28_040000_reconcile_builtin_role_permissions.php');
        $migration->up();

        $this->assertDatabaseHas('roles', [
            'id' => $role->id,
            'code' => 'B2B_ADMIN',
            'scope' => 'global',
            'is_system' => true,
            'is_active' => true,
        ]);

        foreach ([
            'stores.view',
            'b2b.accounts.view',
            'catalog.view',
            'inventory.view',
            'orders.view',
            'drivers.b2b.view',
            'b2b.pricing.view',
            'finance.view',
            'reports.view',
            'settings.view',
        ] as $permission) {
            $this->assertTrue($user->fresh()->hasPermission($permission), "Missing reconciled permission: {$permission}");
        }

        foreach ([
            '/admin/b2b/dashboard',
            '/admin/b2b/clients',
            '/admin/b2b/products',
            '/admin/b2b/inventory',
            '/admin/b2b/orders',
            '/admin/b2b/drivers',
            '/admin/b2b/pricing',
            '/admin/b2b/finance',
            '/admin/b2b/reports',
            '/admin/b2b/settings',
        ] as $uri) {
            $this->actingAs($user)->get($uri)->assertOk();
        }
    }

    public function test_reconciliation_does_not_modify_custom_roles(): void
    {
        $this->seed(CoreReferenceSeeder::class);

        $customRoleId = (int) DB::table('roles')->insertGetId([
            'code' => 'CUSTOM_AUDITOR',
            'name' => 'Custom Auditor',
            'description' => 'Customer-defined role',
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $permissionId = (int) DB::table('permissions')->where('code', 'reports.view')->value('id');
        DB::table('permission_role')->insert([
            'permission_id' => $permissionId,
            'role_id' => $customRoleId,
        ]);

        $migration = require database_path('migrations/2026_09_28_040000_reconcile_builtin_role_permissions.php');
        $migration->up();

        $this->assertDatabaseHas('roles', [
            'id' => $customRoleId,
            'code' => 'CUSTOM_AUDITOR',
            'name' => 'Custom Auditor',
            'scope' => 'global',
            'is_system' => false,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('permission_role', [
            'permission_id' => $permissionId,
            'role_id' => $customRoleId,
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\BuiltInJobRoleCatalog;
use App\Services\OperationalTenantScope;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * V2 #2912 / historical DOCX Table 5, data row 7.
 * Tests use synthetic identities only; never infer the exported user's current state.
 */
final class V2ExportedAccountRow7AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_legacy_wholesale_driver_role_does_not_confer_dashboard_retail_or_unassigned_van_access(): void
    {
        $definition = app(BuiltInJobRoleCatalog::class)->definition('B2B_DRIVER');
        $this->assertIsArray($definition);
        $this->assertSame('global', $definition['scope']);
        $this->assertSame('tenant', $definition['business_scope']);
        $this->assertSame(['deliveries.b2b.execute'], $definition['permissions']);

        $user = $this->syntheticWholesaleDriver();
        $this->assertTrue($user->hasRole('B2B_DRIVER'));
        $this->assertFalse($user->hasRole('B2C_DRIVER'));
        $this->assertFalse($user->hasRole('VAN_OPERATOR'));
        $this->assertSame(['deliveries.b2b.execute'], $user->inheritedPermissionCodes());
        $this->assertTrue($user->hasPermission('deliveries.b2b.execute'));
        $this->assertFalse($user->hasPermission('deliveries.b2c.execute'));
        $this->assertFalse($user->hasPermission('van.login'));
        $this->assertFalse($user->hasPermission('roles.manage'));
        $this->assertSame([], app(OperationalTenantScope::class)->allowedStoreIds(
            $user,
            'inventory.view',
            'b2c',
        ));

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $user->forceFill(['locale' => $locale])->save();
            $actor = $user->fresh();

            $this->assertNoPrivilegedNavigation($actor);
            $this->actingAs($actor)
                ->get(route('admin.security.index', ['section' => 'users']))
                ->assertForbidden();
            $this->actingAs($actor)
                ->get(route('admin.sidebar-access.roles'))
                ->assertForbidden();

            // Driver runtime is Retail B2C only, even when a B2B profile exists.
            Sanctum::actingAs($actor, ['app:driver']);
            $this->postJson('/api/v1/driver/location/heartbeat', [
                'latitude' => 29.3,
                'longitude' => 47.9,
            ])->assertForbidden();

            // Legacy role alone is not an effective authorized Van assignment.
            Sanctum::actingAs($actor, ['app:van']);
            $this->getJson('/api/v1/van/visits')->assertForbidden();
        }

        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => true]);
        $this->assertSame(1, $user->fresh()->roles()->count());
    }

    public function test_forged_retail_allow_cannot_expand_wholesale_driver_role(): void
    {
        $user = $this->syntheticWholesaleDriver();
        $permission = Permission::query()->where('code', 'deliveries.b2c.execute')->firstOrFail();

        DB::table('user_permission_overrides')->insert([
            'user_id' => $user->id,
            'permission_id' => $permission->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'allow',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user->forceFill(['has_permission_overrides' => true])->save();
        $user = $user->fresh();

        $this->assertFalse($user->hasPermission('deliveries.b2c.execute'));
        $this->assertNotContains('deliveries.b2c.execute', $user->effectivePermissionCodes());
        $this->assertFalse($user->hasPermission('users.view'));
        $this->assertFalse($user->hasPermission('van.login'));
        $this->assertNoPrivilegedNavigation($user);

        Sanctum::actingAs($user, ['app:driver', 'app:van']);
        $this->postJson('/api/v1/driver/location/heartbeat', [
            'latitude' => 29.3,
            'longitude' => 47.9,
        ])->assertForbidden();
        $this->getJson('/api/v1/van/visits')->assertForbidden();

        $this->assertDatabaseHas('role_user', [
            'user_id' => $user->id,
            'role_id' => Role::query()->where('code', 'B2B_DRIVER')->firstOrFail()->id,
        ]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => true]);
    }

    /**
     * General account/workspace landing entries can be present for global
     * operational roles. They do not confer Security, Retail or Finance access.
     * Assert absence of privileged actions rather than an entirely empty menu.
     */
    private function assertNoPrivilegedNavigation(User $user): void
    {
        $keys = [];
        foreach (app(AdminNavigation::class)->groupsFor($user) as $group) {
            foreach ((array) ($group['children'] ?? []) as $item) {
                $keys[] = (string) ($item['key'] ?? '');
                foreach ((array) ($item['children'] ?? []) as $child) {
                    $keys[] = (string) ($child['key'] ?? '');
                }
            }
        }

        foreach (['sidebar_role_access', 'b2c_inventory', 'b2b_orders', 'erp_finance', 'erp_wms', 'role_management'] as $restricted) {
            $this->assertNotContains($restricted, $keys);
        }
    }

    private function syntheticWholesaleDriver(): User
    {
        $user = User::factory()->create([
            'name' => 'V2 Row7 Synthetic Legacy Driver',
            'is_active' => true,
            'locale' => 'en',
        ]);
        $user->roles()->attach(Role::query()->where('code', 'B2B_DRIVER')->firstOrFail());

        Driver::query()->create([
            'user_id' => $user->id,
            'driver_type' => 'b2b',
            'is_available' => true,
            'is_active' => true,
        ]);

        return $user->fresh();
    }
}

<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\BuiltInJobRoleCatalog;
use App\Services\OperationalTenantScope;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 #2909: privacy-safe synthetic audit for exported Table 5 data row 4.
 *
 * Historical export: one Retail Store Admin role at one store, with 53
 * distinct inherited permissions at export time. Never copy identifying
 * account data or assert that the historic count is current live authority.
 */
final class V2ExportedRetailStoreAdminRow4AuditTest extends TestCase
{
    use RefreshDatabase;

    private const ROLE_CODE = 'B2C_STORE_ADMIN';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_single_store_admin_does_not_inherit_global_foreign_store_or_wholesale_access_in_both_locales(): void
    {
        $assignedStore = $this->store('B2C', 'V2-2909-ASSIGNED');
        $foreignStore = $this->store('B2C', 'V2-2909-FOREIGN');
        $wholesaleStore = $this->store('B2B', 'V2-2909-WHOLESALE');
        $actor = $this->singleStoreAdmin($assignedStore);
        $scope = app(OperationalTenantScope::class);

        $definition = app(BuiltInJobRoleCatalog::class)->definition(self::ROLE_CODE);
        $this->assertIsArray($definition);
        $this->assertSame('store', $definition['scope']);
        $this->assertSame('store', $definition['business_scope']);
        $this->assertContains('inventory.view', $definition['permissions']);
        $this->assertContains('orders.view', $definition['permissions']);

        $this->assertSame(0, $actor->roles()->count());
        $this->assertSame(1, $actor->storeRoleAssignments()->count());
        $this->assertTrue($actor->hasRole(self::ROLE_CODE, $assignedStore));
        $this->assertFalse($actor->hasRole(self::ROLE_CODE));
        $this->assertFalse($actor->hasRole(self::ROLE_CODE, $foreignStore));
        $this->assertSame([], $actor->inheritedPermissionCodes());
        $this->assertContains('inventory.view', $actor->inheritedPermissionCodes($assignedStore));
        $this->assertSame([], $actor->inheritedPermissionCodes($foreignStore));
        $this->assertSame([], $actor->inheritedPermissionCodes($wholesaleStore));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $actor = $actor->fresh();

            $this->assertTrue($actor->hasPermission('inventory.view', $assignedStore));
            $this->assertFalse($actor->hasPermission('inventory.view', $foreignStore));
            $this->assertFalse($actor->hasPermission('inventory.view', $wholesaleStore));
            $this->assertFalse($actor->hasPermission('inventory.view'));
            $this->assertFalse($actor->hasPermission('users.delete'));
            $this->assertFalse($actor->hasPermission('roles.manage'));
            $this->assertSame([$assignedStore], $scope->allowedStoreIds($actor, 'inventory.view', 'b2c'));
            $this->assertSame([], $scope->allowedStoreIds($actor, 'inventory.view', 'b2b'));

            $channels = app(AdminNavigation::class)->for($actor);
            $this->assertArrayHasKey('b2c', $channels);
            $this->assertArrayNotHasKey('b2b', $channels);

            $this->actingAs($actor)
                ->get(route('admin.b2c.module', [
                    'module' => 'inventory',
                    'store_id' => $assignedStore,
                ]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            $foreign = $this->actingAs($actor)->get(route('admin.b2c.module', [
                'module' => 'inventory',
                'store_id' => $foreignStore,
            ]));
            $this->assertContains($foreign->getStatusCode(), [403, 404]);

            $this->actingAs($actor)->get(route('admin.sidebar-access.roles'))
                ->assertForbidden();

            $wholesale = $this->actingAs($actor)->get(route('admin.b2b.module', [
                'module' => 'inventory',
            ]));
            $this->assertContains($wholesale->getStatusCode(), [403, 404]);
        }
    }

    public function test_forged_foreign_store_allow_and_global_privilege_allow_do_not_extend_the_role(): void
    {
        $assignedStore = $this->store('B2C', 'V2-2909-ALLOW-ASSIGNED');
        $foreignStore = $this->store('B2C', 'V2-2909-ALLOW-FOREIGN');
        $actor = $this->singleStoreAdmin($assignedStore);

        foreach ([
            ['orders.view', 'store:'.$foreignStore, $foreignStore],
            ['roles.manage', 'global', null],
            ['users.delete', 'global', null],
        ] as [$permission, $scopeKey, $storeId]) {
            DB::table('user_permission_overrides')->insert([
                'user_id' => $actor->getKey(),
                'permission_id' => Permission::query()->where('code', $permission)->firstOrFail()->getKey(),
                'scope_key' => $scopeKey,
                'store_id' => $storeId,
                'effect' => 'allow',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $actor->forceFill(['has_permission_overrides' => true])->save();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $actor = $actor->fresh();

            $this->assertTrue($actor->hasPermission('orders.view', $assignedStore));
            $this->assertFalse($actor->hasPermission('orders.view', $foreignStore));
            $this->assertFalse($actor->hasPermission('roles.manage'));
            $this->assertFalse($actor->hasPermission('users.delete'));
            $this->assertSame([$assignedStore], app(OperationalTenantScope::class)
                ->allowedStoreIds($actor, 'orders.view', 'b2c'));
            $this->assertNotContains('roles.manage', $actor->effectivePermissionCodes());

            $response = $this->actingAs($actor)->get(route('admin.b2c.module', [
                'module' => 'orders',
                'store_id' => $foreignStore,
            ]));
            $this->assertContains($response->getStatusCode(), [403, 404]);

            $this->actingAs($actor)->get(route('admin.sidebar-access.roles'))
                ->assertForbidden();
        }

        $this->assertSame(0, $actor->roles()->count());
        $this->assertSame(1, $actor->storeRoleAssignments()->count());
    }

    public function test_explicit_deny_and_deactivation_close_operational_scope_without_removing_identity(): void
    {
        $store = $this->store('B2C', 'V2-2909-DENY');
        $actor = $this->singleStoreAdmin($store);
        $role = Role::query()->where('code', self::ROLE_CODE)->firstOrFail();
        $rolePermissionsBefore = $role->permissions()->count();

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->getKey(),
            'permission_id' => Permission::query()->where('code', 'inventory.view')->firstOrFail()->getKey(),
            'scope_key' => 'store:'.$store,
            'store_id' => $store,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            $actor = $actor->fresh();

            $this->assertFalse($actor->hasPermission('inventory.view', $store));
            $this->assertNotContains('inventory.view', $actor->effectivePermissionCodes($store));
            $this->assertSame([], app(OperationalTenantScope::class)->allowedStoreIds(
                $actor,
                'inventory.view',
                'b2c',
            ));

            $response = $this->actingAs($actor)->get(route('admin.b2c.module', [
                'module' => 'inventory',
                'store_id' => $store,
            ]));
            $this->assertContains($response->getStatusCode(), [403, 404]);
        }

        $actor->forceFill(['is_active' => false])->save();
        $actor = $actor->fresh();

        $this->assertSame([], app(OperationalTenantScope::class)
            ->allowedStoreIds($actor, 'orders.view', 'b2c'));
        $this->assertSame([], app(AdminNavigation::class)->groupsFor($actor));
        $this->assertDatabaseHas('users', ['id' => $actor->getKey()]);
        $this->assertDatabaseHas('user_store_roles', [
            'user_id' => $actor->getKey(),
            'role_id' => $role->getKey(),
            'store_id' => $store,
        ]);
        $this->assertSame($rolePermissionsBefore, $role->fresh()->permissions()->count());
    }

    private function singleStoreAdmin(int $storeId): User
    {
        $role = Role::query()->where('code', self::ROLE_CODE)->firstOrFail();
        $this->assertSame('store', $role->scope);
        $this->assertTrue((bool) $role->is_active);

        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->getKey(),
            'role_id' => $role->getKey(),
            'store_id' => $storeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor->fresh();
    }

    private function store(string $channel, string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', $channel)->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

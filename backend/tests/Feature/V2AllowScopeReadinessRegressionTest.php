<?php

namespace Tests\Feature;

use App\Enterprise\Modules\ModuleLifecycleManager;
use App\Enterprise\Modules\ModuleRegistry;
use App\Enterprise\Modules\ModuleState;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\OperationalTenantScope;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 #2863 / P141: an explicit ALLOW is still subject to the active store,
 * business channel, global-role context and real module route readiness.
 *
 * Forged/stale override rows deliberately exercise the read-side boundary:
 * normal writes also require the privileged security administration policy.
 */
final class V2AllowScopeReadinessRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_store_allow_never_crosses_assigned_store_or_wholesale_http_boundary(): void
    {
        $assigned = $this->store('B2C', 'P141-RETAIL-A');
        $foreign = $this->store('B2C', 'P141-RETAIL-B');
        $wholesale = $this->store('B2B', 'P141-WHOLESALE');
        $actor = $this->retailOperator($assigned);

        // Simulate a stale/forged allow for another store and for wholesale.
        $this->allow($actor, 'orders.manage', 'store:'.$foreign, $foreign);
        $this->allow($actor, 'b2b.accounts.view', 'global');

        $fresh = $actor->fresh();
        $this->assertTrue($fresh->hasPermission('orders.manage', $assigned));
        $this->assertFalse($fresh->hasPermission('orders.manage', $foreign));
        $this->assertFalse($fresh->hasPermission('orders.manage', $wholesale));
        $this->assertFalse($fresh->hasPermission('b2b.accounts.view', $wholesale));
        $this->assertNotContains('orders.manage', $fresh->effectivePermissionCodes($foreign));

        $this->assertSame(
            [$assigned],
            app(OperationalTenantScope::class)->allowedStoreIds($fresh, 'orders.manage', 'b2c'),
        );
        $this->assertSame(
            [],
            app(OperationalTenantScope::class)->allowedStoreIds($fresh, 'orders.manage', 'b2b'),
        );

        $this->actingAs($fresh)
            ->get('/admin/b2c/orders?store_id='.$assigned)
            ->assertOk();
        $this->actingAs($fresh)
            ->get('/admin/b2c/orders?store_id='.$foreign)
            ->assertNotFound();
        $this->actingAs($fresh)
            ->get('/admin/b2b/orders')
            ->assertForbidden();
    }

    public function test_multi_role_global_allow_respects_business_channel_and_store_boundaries(): void
    {
        $retail = $this->store('B2C', 'P141-MIXED-RETAIL');
        $foreign = $this->store('B2C', 'P141-MIXED-FOREIGN');
        $wholesale = $this->store('B2B', 'P141-MIXED-WHOLESALE');
        $actor = $this->retailOperator($retail);
        $actor->roles()->attach(Role::query()->where('code', 'OPERATIONS')->firstOrFail());

        $this->allow($actor, 'b2b.accounts.view', 'global');
        $this->allow($actor, 'drivers.b2c.manage', 'global');

        $fresh = $actor->fresh();
        $this->assertTrue($fresh->hasPermission('b2b.accounts.view', $wholesale));
        $this->assertFalse($fresh->hasPermission('b2b.accounts.view', $retail));
        $this->assertFalse($fresh->hasPermission('b2b.accounts.view', $foreign));
        $this->assertFalse($fresh->hasPermission('drivers.b2c.manage', $wholesale));
        $this->assertTrue($fresh->hasPermission('orders.manage', $retail));
        $this->assertFalse($fresh->hasPermission('orders.manage', $foreign));
        $this->assertNotContains('b2b.accounts.view', $fresh->effectivePermissionCodes($retail));
        $this->assertNotContains('drivers.b2c.manage', $fresh->effectivePermissionCodes($wholesale));

        $scope = app(OperationalTenantScope::class);
        $this->assertSame([$retail], $scope->allowedStoreIds($fresh, 'orders.manage', 'b2c'));
        $this->assertSame([$wholesale], $scope->allowedStoreIds($fresh, 'orders.manage', 'b2b'));

        $this->actingAs($fresh)
            ->get('/admin/b2c/orders?store_id='.$foreign)
            ->assertNotFound();
    }

    public function test_explicit_allow_cannot_open_disabled_procurement_module_route(): void
    {
        $this->store('B2B', 'P141-PROCUREMENT-WHOLESALE');
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach([
            Role::query()->where('code', 'B2B_ADMIN')->firstOrFail()->id,
            Role::query()->where('code', 'PROCUREMENT_MANAGER')->firstOrFail()->id,
        ]);
        $this->allow($actor, 'procurement.suppliers.view', 'global');

        $registry = app(ModuleRegistry::class);
        $registry->syncManifest('procurement');
        $this->assertFalse($registry->isEnabled('procurement'));

        foreach (['en', 'ar'] as $locale) {
            App::setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $this->actingAs($actor->fresh())
                ->get('/admin/erp/procurement?tab=suppliers')
                ->assertNotFound();
        }

        $this->enableProcurement();

        $this->actingAs($actor->fresh())
            ->get('/admin/erp/procurement?tab=suppliers')
            ->assertOk();
    }

    public function test_non_security_actor_cannot_write_user_allow_override_via_http(): void
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());
        $target = User::factory()->create(['is_active' => true]);
        $target->roles()->attach(Role::query()->where('code', 'OPERATIONS')->firstOrFail());
        $permission = Permission::query()->where('code', 'orders.manage')->firstOrFail();

        foreach (['en', 'ar'] as $locale) {
            App::setLocale($locale);
            $this->actingAs($actor)
                ->put(route('admin.security.users.permissions', $target), [
                    'scope_type' => 'global',
                    'permission_ids' => [$permission->id],
                ])
                ->assertForbidden();
        }

        $this->assertDatabaseMissing('user_permission_overrides', [
            'user_id' => $target->id,
            'permission_id' => $permission->id,
            'effect' => 'allow',
        ]);
    }

    private function store(string $type, string $code): int
    {
        $typeId = (int) DB::table('store_types')->where('code', $type)->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function retailOperator(int $storeId): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'RETAIL_OPERATIONS')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor->fresh();
    }

    private function allow(User $actor, string $permissionCode, string $scopeKey, ?int $storeId = null): void
    {
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', $permissionCode)->firstOrFail()->id,
            'scope_key' => $scopeKey,
            'store_id' => $storeId,
            'effect' => 'allow',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
    }

    private function enableProcurement(): void
    {
        $registry = app(ModuleRegistry::class);
        if ($registry->state('procurement') === ModuleState::INSTALLED) {
            app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::DISABLED);
        }
        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::ENABLING);
        app(ModuleLifecycleManager::class)->transition('procurement', ModuleState::ENABLED);
    }
}

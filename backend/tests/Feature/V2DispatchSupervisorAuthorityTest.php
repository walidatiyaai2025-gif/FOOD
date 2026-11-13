<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreType;
use App\Models\User;
use App\Services\BuiltInJobRoleCatalog;
use App\Services\OperationalTenantScope;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T122R21: Dispatch Supervisor is a Wholesale Van dispatcher, never a Retail
 * Driver or an all-purpose Finance/Security operator. Server scope is canonical.
 */
final class V2DispatchSupervisorAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_seeded_dispatch_supervisor_grants_only_wholesale_operations_without_retail_finance_or_security(): void
    {
        $definition = app(BuiltInJobRoleCatalog::class)->definition('DISPATCH_SUPERVISOR');
        $this->assertIsArray($definition);
        $this->assertSame('field_operations', $definition['domain']);
        $this->assertSame('global', $definition['scope']);
        $this->assertSame('tenant', $definition['business_scope']);
        $this->assertSame([], $definition['required_modules']);
        $this->assertEqualsCanonicalizing([
            'orders.view', 'orders.dispatch', 'drivers.tracking.view',
            'drivers.b2b.view', 'drivers.b2b.manage', 'reports.view',
        ], $definition['permissions']);

        $role = Role::query()->where('code', 'DISPATCH_SUPERVISOR')->firstOrFail();
        $this->assertEqualsCanonicalizing($definition['permissions'], $role->permissions()->pluck('code')->all());
        $this->assertContains('DISPATCH_SUPERVISOR', config('admin.channels.b2b.global_roles'));
        $this->assertNotContains('DISPATCH_SUPERVISOR', config('admin.channels.b2c.global_roles'));

        $actor = $this->dispatchSupervisor();
        foreach ([
            'finance.view', 'finance.manage', 'finance.post', 'finance.approve',
            'security.view', 'roles.manage', 'orders.approve',
            'drivers.b2c.view', 'drivers.b2c.manage', 'deliveries.b2c.execute',
            'deliveries.b2b.execute', 'field_ops.manage', 'territories.manage',
        ] as $permission) {
            $this->assertFalse($actor->hasPermission($permission), $permission);
        }

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $presentation = app(BuiltInJobRoleCatalog::class)->presentation('DISPATCH_SUPERVISOR');
            $this->assertNotEmpty($presentation['name']);
            $this->assertNotEmpty($presentation['description']);
            $this->assertNotEmpty($presentation['access_boundaries']);
        }
    }

    public function test_wholesale_dispatch_supervisor_can_open_real_orders_and_field_ops_in_both_locales(): void
    {
        $wholesaleId = app(WholesalePrincipal::class)->storeId();
        $actor = $this->dispatchSupervisor();
        $scope = app(OperationalTenantScope::class);

        $this->assertContains($wholesaleId, $scope->allowedStoreIds($actor, 'orders.view', 'B2B'));
        $this->assertContains($wholesaleId, $scope->allowedStoreIds($actor, 'drivers.b2b.view', 'B2B'));
        $this->assertSame([], $scope->allowedStoreIds($actor, 'orders.view', 'B2C'));
        $this->assertSame([], $scope->allowedStoreIds($actor, 'drivers.b2b.view', 'B2C'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            app()->forgetInstance(AdminNavigation::class);
            $navigation = app(AdminNavigation::class);
            $this->assertTrue($navigation->canAccess($actor->fresh(), 'b2b'));
            $this->assertFalse($navigation->canAccess($actor->fresh(), 'b2c'));

            $groups = collect($navigation->groupsFor($actor->fresh()));
            $orders = $groups->firstWhere('key', 'operations');
            $this->assertIsArray($orders);
            $this->assertContains('order_operations', collect($orders['children'])->pluck('key')->all());
            $field = $groups->firstWhere('key', 'field_operations');
            $this->assertIsArray($field);
            $keys = collect($field['children'])->pluck('key')->all();
            $this->assertContains('field_ops_overview', $keys);
            $this->assertContains('field_ops_vans', $keys);
            $this->assertNotContains('field_ops_territories', $keys);
            $this->assertNotContains('field_ops_finance', $keys);

            $this->actingAs($actor->fresh())
                ->get(route('admin.operations.orders.index', [], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
            $this->actingAs($actor->fresh())
                ->get(route('admin.field-operations.overview', ['store_id' => $wholesaleId], false))
                ->assertOk();
        }
    }

    public function test_retail_store_and_finance_security_and_dispatch_execution_are_not_granted(): void
    {
        app(WholesalePrincipal::class)->storeId();
        $retail = $this->store('B2C', 'V2-2701-RETAIL');
        $actor = $this->dispatchSupervisor();

        $this->actingAs($actor)
            ->get(route('admin.b2c.dashboard', ['store_id' => $retail->id], false))
            ->assertForbidden();
        $this->actingAs($actor)
            ->get(route('admin.operations.orders.index', ['channel' => 'b2c', 'store_id' => $retail->id], false))
            ->assertForbidden();
        $this->actingAs($actor)
            ->get(route('admin.field-operations.overview', ['store_id' => $retail->id], false))
            ->assertForbidden();
        $this->actingAs($actor)
            ->get(route('admin.field-operations.finance', [], false))
            ->assertForbidden();
        $this->actingAs($actor)
            ->get(route('admin.security.index', [], false))
            ->assertForbidden();

        $this->assertTrue($actor->hasPermission('orders.dispatch'));
        $this->assertFalse($actor->hasPermission('deliveries.b2c.execute'));
        $this->assertFalse($actor->hasPermission('deliveries.b2b.execute'));
    }

    public function test_explicit_store_deny_removes_a_wholesale_store_from_field_operations_without_retail_fallback(): void
    {
        $own = app(WholesalePrincipal::class)->storeId();
        $foreign = $this->store('B2B', 'V2-2701-FOREIGN');
        $actor = $this->dispatchSupervisor();
        $scope = app(OperationalTenantScope::class);

        $this->assertContains($foreign->id, $scope->allowedStoreIds($actor, 'drivers.b2b.view', 'B2B'));
        $this->deny($actor, 'drivers.b2b.view', 'store:'.$foreign->id, $foreign->id);

        $allowed = $scope->allowedStoreIds($actor->fresh(), 'drivers.b2b.view', 'B2B');
        $this->assertContains($own, $allowed);
        $this->assertNotContains($foreign->id, $allowed);

        $this->actingAs($actor->fresh())
            ->get(route('admin.field-operations.overview', ['store_id' => $foreign->id], false))
            ->assertForbidden();
        $this->actingAs($actor->fresh())
            ->get(route('admin.field-operations.overview', ['store_id' => $own], false))
            ->assertOk();
    }

    public function test_global_orders_deny_blocks_orders_without_granting_new_roles(): void
    {
        app(WholesalePrincipal::class)->storeId();
        $actor = $this->dispatchSupervisor();
        $this->deny($actor, 'orders.view', 'global', null);

        $this->assertSame([], app(OperationalTenantScope::class)
            ->allowedStoreIds($actor->fresh(), 'orders.view', 'B2B'));

        app()->forgetInstance(AdminNavigation::class);
        $operations = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
            ->firstWhere('key', 'operations');
        $this->assertNotContains('order_operations', collect($operations['children'] ?? [])->pluck('key')->all());

        $this->actingAs($actor->fresh())
            ->get(route('admin.operations.orders.index', [], false))
            ->assertForbidden();
    }

    public function test_global_van_view_deny_and_roleless_or_inactive_identity_fail_closed(): void
    {
        app(WholesalePrincipal::class)->storeId();
        $actor = $this->dispatchSupervisor();
        $this->deny($actor, 'drivers.b2b.view', 'global', null);

        $this->assertSame([], app(OperationalTenantScope::class)
            ->allowedStoreIds($actor->fresh(), 'drivers.b2b.view', 'B2B'));
        $this->actingAs($actor->fresh())
            ->get(route('admin.field-operations.vans', [], false))
            ->assertForbidden();

        $inactive = $this->dispatchSupervisor();
        $inactive->forceFill(['is_active' => false])->save();
        $roleless = User::factory()->create(['is_active' => true]);

        foreach ([$inactive->fresh(), $roleless] as $identity) {
            $this->actingAs($identity)
                ->get(route('admin.operations.orders.index', [], false))
                ->assertForbidden();
            $this->actingAs($identity)
                ->get(route('admin.field-operations.vans', [], false))
                ->assertForbidden();
        }
    }

    private function dispatchSupervisor(): User
    {
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'DISPATCH_SUPERVISOR')->firstOrFail());

        return $actor->fresh();
    }

    private function store(string $channel, string $code): Store
    {
        $type = StoreType::query()->firstOrCreate(['code' => $channel], ['name' => $channel]);

        return Store::query()->create([
            'store_type_id' => $type->id,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
    }

    private function deny(User $actor, string $ability, string $scopeKey, ?int $storeId): void
    {
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', $ability)->firstOrFail()->id,
            'scope_key' => $scopeKey,
            'store_id' => $storeId,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
    }
}

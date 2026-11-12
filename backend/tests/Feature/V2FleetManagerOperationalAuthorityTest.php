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

final class V2FleetManagerOperationalAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_builtin_fleet_manager_role_is_b2b_only_and_does_not_grant_finance_or_dispatch(): void
    {
        $definition = app(BuiltInJobRoleCatalog::class)->definition('FLEET_MANAGER');
        $this->assertIsArray($definition);
        $this->assertSame('field_operations', $definition['domain']);
        $this->assertSame('global', $definition['scope']);
        $this->assertSame('tenant', $definition['business_scope']);
        $this->assertSame([], $definition['required_modules']);
        $this->assertEqualsCanonicalizing([
            'field_ops.manage', 'territories.manage', 'drivers.tracking.view',
            'drivers.tracking.history', 'drivers.tracking.manage', 'drivers.b2b.view',
            'drivers.b2b.manage', 'reports.view',
        ], $definition['permissions']);

        $role = Role::query()->where('code', 'FLEET_MANAGER')->firstOrFail();
        $this->assertEqualsCanonicalizing($definition['permissions'], $role->permissions()->pluck('code')->all());
        $this->assertContains('FLEET_MANAGER', config('admin.channels.b2b.global_roles'));
        $this->assertNotContains('FLEET_MANAGER', config('admin.channels.b2c.global_roles'));

        $actor = $this->fleetManager();
        foreach ([
            'finance.view', 'finance.manage', 'finance.post', 'finance.approve',
            'security.view', 'roles.manage', 'orders.dispatch',
            'drivers.b2c.view', 'drivers.b2c.manage', 'deliveries.b2c.execute',
        ] as $code) {
            $this->assertFalse($actor->hasPermission($code), $code);
        }

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $presentation = app(BuiltInJobRoleCatalog::class)->presentation('FLEET_MANAGER');
            $this->assertNotEmpty($presentation['name']);
            $this->assertNotEmpty($presentation['description']);
            $this->assertNotEmpty($presentation['access_boundaries']);
        }
    }

    public function test_wholesale_manager_gets_authorized_fleet_dashboard_and_cannot_open_retail_or_finance(): void
    {
        $wholesaleId = app(WholesalePrincipal::class)->storeId();
        $retailId = $this->store('B2C', 'V2-2700-RETAIL')->id;
        $actor = $this->fleetManager();

        $this->assertContains($wholesaleId, app(OperationalTenantScope::class)
            ->allowedStoreIds($actor, 'drivers.b2b.view', 'B2B'));
        $this->assertSame([], app(OperationalTenantScope::class)
            ->allowedStoreIds($actor, 'drivers.b2b.view', 'B2C'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            app()->forgetInstance(AdminNavigation::class);
            $navigation = app(AdminNavigation::class);
            $this->assertTrue($navigation->canAccess($actor->fresh(), 'b2b'));
            $this->assertFalse($navigation->canAccess($actor->fresh(), 'b2c'));

            $field = collect($navigation->groupsFor($actor->fresh()))
                ->firstWhere('key', 'field_operations');
            $this->assertIsArray($field);
            $this->assertContains('field_ops_vans', collect($field['children'])->pluck('key')->all());

            $this->actingAs($actor->fresh())
                ->get(route('admin.field-operations.vans', [], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
            $this->actingAs($actor->fresh())
                ->get(route('admin.field-operations.overview', ['store_id' => $wholesaleId], false))
                ->assertOk();
        }

        $this->actingAs($actor->fresh())
            ->get(route('admin.b2c.dashboard', ['store_id' => $retailId], false))
            ->assertForbidden();
        $this->actingAs($actor->fresh())
            ->get(route('admin.field-operations.overview', ['store_id' => $retailId], false))
            ->assertForbidden();
        $this->actingAs($actor->fresh())
            ->get(route('admin.field-operations.finance', [], false))
            ->assertForbidden();
        $this->actingAs($actor->fresh())
            ->get(route('admin.security.index', [], false))
            ->assertForbidden();
    }

    public function test_store_deny_removes_foreign_wholesale_from_real_overview_but_keeps_own_store(): void
    {
        $wholesaleId = app(WholesalePrincipal::class)->storeId();
        $foreignId = $this->store('B2B', 'V2-2700-FOREIGN-WHOLESALE')->id;
        $actor = $this->fleetManager();
        $scope = app(OperationalTenantScope::class);

        $this->assertContains($foreignId, $scope->allowedStoreIds($actor, 'drivers.b2b.view', 'B2B'));

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', 'drivers.b2b.view')->firstOrFail()->id,
            'scope_key' => 'store:'.$foreignId,
            'store_id' => $foreignId,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $allowed = $scope->allowedStoreIds($actor->fresh(), 'drivers.b2b.view', 'B2B');
        $this->assertContains($wholesaleId, $allowed);
        $this->assertNotContains($foreignId, $allowed);
        $this->actingAs($actor->fresh())
            ->get(route('admin.field-operations.overview', ['store_id' => $foreignId], false))
            ->assertForbidden();
        $this->actingAs($actor->fresh())
            ->get(route('admin.field-operations.overview', ['store_id' => $wholesaleId], false))
            ->assertOk();
    }

    public function test_global_deny_and_inactive_or_roleless_users_do_not_gain_fleet_access(): void
    {
        app(WholesalePrincipal::class)->storeId();
        $actor = $this->fleetManager();
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', 'drivers.b2b.view')->firstOrFail()->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
        $this->assertSame([], app(OperationalTenantScope::class)
            ->allowedStoreIds($actor->fresh(), 'drivers.b2b.view', 'B2B'));
        $this->actingAs($actor->fresh())
            ->get(route('admin.field-operations.vans', [], false))
            ->assertForbidden();

        $inactive = $this->fleetManager();
        $inactive->forceFill(['is_active' => false])->save();
        $roleless = User::factory()->create(['is_active' => true]);

        foreach ([$inactive->fresh(), $roleless] as $identity) {
            $this->actingAs($identity)
                ->get(route('admin.field-operations.vans', [], false))
                ->assertForbidden();
        }
    }

    private function fleetManager(): User
    {
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'FLEET_MANAGER')->firstOrFail());

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
}

<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\BuiltInJobRoleCatalog;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 #2681 / source T122R1: B2B_ADMIN must remain a Wholesale operational
 * authority, never an implicit Platform Owner, Retail owner or finance checker.
 */
final class V2B2bAdminWholesaleAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_role_definition_and_seeded_grants_are_explicit_and_bilingual(): void
    {
        $catalog = app(BuiltInJobRoleCatalog::class);
        $definition = $catalog->definition('B2B_ADMIN');
        $this->assertIsArray($definition);
        $this->assertSame('b2b', $definition['domain']);
        $this->assertSame('tenant', $definition['business_scope']);
        $this->assertSame('global', $definition['scope']);
        $this->assertSame([], $definition['required_modules']);
        $this->assertNotContains('*', $definition['permissions']);

        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();
        $this->assertTrue((bool) $role->is_system);
        $this->assertEqualsCanonicalizing(
            $definition['permissions'],
            $role->permissions()->pluck('code')->all(),
        );

        $actor = $this->manager('wholesale-profile@example.test');
        foreach (['orders.view', 'orders.dispatch', 'catalog.manage', 'finance.view', 'reports.export', 'drivers.b2b.view'] as $code) {
            $this->assertTrue($actor->hasPermission($code), $code.' must come from canonical Wholesale grants');
        }
        foreach ([
            'security.view', 'roles.manage', 'users.roles.manage',
            'finance.post', 'finance.approve', 'procurement.purchase_orders.issue',
            'drivers.b2c.view', 'deliveries.b2c.execute', 'deliveries.b2b.execute', 'van.login',
        ] as $forbidden) {
            $this->assertFalse($actor->hasPermission($forbidden), $forbidden.' must not be inherited as blanket administration');
        }
        $this->assertFalse($actor->hasRole('SUPER_ADMIN'));
        $this->assertFalse($actor->isPlatformOwner());

        foreach (['ar' => 'مدير أعمال الجملة', 'en' => 'B2B Manager'] as $locale => $name) {
            app()->setLocale($locale);
            $presentation = $catalog->presentation('B2B_ADMIN');
            $this->assertSame($name, $presentation['name']);
            $this->assertNotEmpty($presentation['description']);
            $this->assertNotEmpty($presentation['access_boundaries']);
        }
    }

    public function test_wholesale_dashboard_and_operations_are_reachable_in_both_locales_without_security_administration(): void
    {
        $actor = $this->manager('wholesale-http@example.test');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);
            app()->forgetInstance(AdminNavigation::class);
            $visible = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
                ->flatMap(static fn (array $group): array => $group['children'])
                ->keyBy('key');

            $this->assertTrue($visible->has('b2b_dashboard'));
            $this->assertTrue($visible->has('order_operations'));
            $this->assertFalse($visible->has('b2c_dashboard'));
            $this->assertFalse($visible->has('sidebar_role_access'));
            $this->assertFalse($visible->has('mobile_driver_download'));

            $dashboard = $this->actingAs($actor->fresh())->get(route('admin.b2b.dashboard', [], false));
            $dashboard->assertOk()->assertSee('dir="'.$direction.'"', false);
            $this->actingAs($actor->fresh())
                ->get(route('admin.operations.orders.index', [], false))
                ->assertOk();

            $this->actingAs($actor->fresh())
                ->get(route('admin.security.index', [], false))
                ->assertForbidden();
        }
    }

    public function test_wholesale_permissions_do_not_cross_into_retail_store_even_with_global_allow_override(): void
    {
        $actor = $this->manager('wholesale-isolation@example.test');
        $wholesaleId = app(WholesalePrincipal::class)->storeId();
        $retailId = $this->retailStore('V2-B2B-RETAIL-CROSS');
        $otherRetailId = $this->retailStore('V2-B2B-RETAIL-OTHER');

        $this->assertTrue($actor->hasRole('B2B_ADMIN', $wholesaleId));
        $this->assertFalse($actor->hasRole('B2B_ADMIN', $retailId));
        $this->assertTrue($actor->hasPermission('orders.view', $wholesaleId));
        $this->assertFalse($actor->hasPermission('orders.view', $retailId));
        $this->assertFalse($actor->hasPermission('orders.view', $otherRetailId));

        $this->actingAs($actor)->get(route('admin.b2c.dashboard', ['store_id' => $retailId], false))
            ->assertForbidden();

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', 'orders.view')->firstOrFail()->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'allow',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $this->assertFalse($actor->fresh()->hasPermission('orders.view', $retailId));
        $this->assertFalse($actor->fresh()->hasPermission('orders.view', $otherRetailId));
        $this->actingAs($actor->fresh())
            ->get(route('admin.b2c.dashboard', ['store_id' => $otherRetailId], false))
            ->assertForbidden();
    }

    public function test_explicit_deny_revokes_wholesale_order_navigation_and_direct_http_without_changing_role(): void
    {
        $actor = $this->manager('wholesale-deny@example.test');
        $role = Role::query()->where('code', 'B2B_ADMIN')->firstOrFail();

        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', 'orders.view')->firstOrFail()->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();

        $this->assertFalse($actor->fresh()->hasPermission('orders.view'));
        $this->assertTrue($actor->fresh()->hasPermission('catalog.view'));
        app()->forgetInstance(AdminNavigation::class);
        $visible = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->pluck('key');

        $this->assertNotContains('order_operations', $visible->all());
        $this->actingAs($actor->fresh())
            ->get(route('admin.operations.orders.index', [], false))
            ->assertForbidden();
        $this->assertDatabaseHas('role_user', ['user_id' => $actor->id, 'role_id' => $role->id]);
    }

    public function test_inactive_and_roleless_identities_cannot_open_wholesale_or_security_surfaces(): void
    {
        $inactive = $this->manager('wholesale-disabled@example.test');
        $inactive->forceFill(['is_active' => false])->save();
        $roleless = User::factory()->create(['is_active' => true]);

        foreach ([$inactive->fresh(), $roleless] as $identity) {
            app()->forgetInstance(AdminNavigation::class);
            $this->assertSame([], app(AdminNavigation::class)->groupsFor($identity));
            $this->actingAs($identity)
                ->get(route('admin.b2b.dashboard', [], false))
                ->assertForbidden();
            $this->actingAs($identity)
                ->get(route('admin.security.index', [], false))
                ->assertForbidden();
        }
    }

    private function manager(string $email): User
    {
        $actor = User::factory()->create(['email' => $email, 'locale' => 'en', 'is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        return $actor->fresh();
    }

    private function retailStore(string $code): int
    {
        $type = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $type,
            'business_profile' => 'RETAIL_STORE',
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

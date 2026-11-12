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
 * V2 #2685 / T122R5: Customer Service Manager authority is operational and
 * Wholesale-scoped; presentation and overrides must never widen the role.
 */
final class V2CustomerServiceManagerAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_seeded_customer_service_manager_is_bilingual_and_not_platform_or_finance_admin(): void
    {
        $catalog = app(BuiltInJobRoleCatalog::class);
        $definition = $catalog->definition('CUSTOMER_SERVICE_MANAGER');
        $this->assertIsArray($definition);
        $this->assertSame('customer_service', $definition['domain']);
        $this->assertSame('tenant', $definition['business_scope']);
        $this->assertSame('global', $definition['scope']);
        $this->assertSame([], $definition['required_modules']);
        $this->assertNotContains('*', $definition['permissions']);

        $role = Role::query()->where('code', 'CUSTOMER_SERVICE_MANAGER')->firstOrFail();
        $this->assertTrue((bool) $role->is_system);
        $this->assertEqualsCanonicalizing(
            $definition['permissions'],
            $role->permissions()->pluck('code')->all(),
        );

        $manager = $this->manager('v2-csm-permissions@example.test');
        foreach (['customer_service.team.manage', 'support.manage', 'customers.view', 'orders.view', 'orders.dispatch', 'whatsapp.inbox.view'] as $code) {
            $this->assertTrue($manager->hasPermission($code), $code.' should be inherited');
        }
        foreach ([
            'security.view', 'roles.manage', 'users.roles.manage',
            'finance.post', 'finance.approve', 'drivers.tracking.view',
            'drivers.b2c.view', 'deliveries.b2c.execute', 'deliveries.b2b.execute', 'van.login',
        ] as $code) {
            $this->assertFalse($manager->hasPermission($code), $code.' must not be granted');
        }
        $this->assertFalse($manager->hasRole('SUPER_ADMIN'));
        $this->assertFalse($manager->isPlatformOwner());

        foreach (['ar' => 'مدير خدمة العملاء', 'en' => 'Customer Service Manager'] as $locale => $label) {
            app()->setLocale($locale);
            $presentation = $catalog->presentation('CUSTOMER_SERVICE_MANAGER');
            $this->assertSame($label, $presentation['name']);
            $this->assertNotEmpty($presentation['description']);
            $this->assertNotEmpty($presentation['access_boundaries']);
        }
    }

    public function test_wholesale_service_workspace_is_discoverable_in_ar_and_en_but_security_is_forbidden(): void
    {
        // The protected Wholesale HTTP workspace requires the principal store
        // fixture, as in the canonical CustomerServiceRoleClosureTest suite.
        app(WholesalePrincipal::class)->storeId();
        $manager = $this->manager('v2-csm-http@example.test');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $manager->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);
            app()->forgetInstance(AdminNavigation::class);

            $groups = collect(app(AdminNavigation::class)->groupsFor($manager->fresh()));
            $keys = $groups->flatMap(static fn (array $group): array => $group['children'])->pluck('key');
            $this->assertContains('erp_customer_service', $keys->all());
            $this->assertContains('customer_360', $keys->all());
            $this->assertNotContains('erp_center', $keys->all());
            $this->assertNotContains('administration_hub', $keys->all());
            $this->assertNotContains('driver_live_tracking', $keys->all());
            $this->assertFalse($groups->contains('key', 'field_operations'));

            $this->actingAs($manager->fresh())
                ->get(route('admin.erp.customer-service', [], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
            $this->actingAs($manager->fresh())
                ->get(route('admin.security.index', [], false))
                ->assertForbidden();
        }
    }

    public function test_global_customer_service_grants_cannot_access_two_retail_stores_even_after_direct_allow(): void
    {
        $manager = $this->manager('v2-csm-retail-isolation@example.test');
        $wholesale = app(WholesalePrincipal::class)->storeId();
        $retailA = $this->retailStore('CSM-RETAIL-A');
        $retailB = $this->retailStore('CSM-RETAIL-B');

        $this->assertTrue($manager->hasPermission('customers.view', $wholesale));
        $this->assertFalse($manager->hasPermission('customers.view', $retailA));
        $this->assertFalse($manager->hasPermission('customers.view', $retailB));

        $this->actingAs($manager)
            ->get(route('admin.b2c.dashboard', ['store_id' => $retailA], false))
            ->assertForbidden();

        DB::table('user_permission_overrides')->insert([
            'user_id' => $manager->id,
            'permission_id' => Permission::query()->where('code', 'customers.view')->firstOrFail()->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'allow',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $manager->forceFill(['has_permission_overrides' => true])->save();

        $this->assertFalse($manager->fresh()->hasPermission('customers.view', $retailA));
        $this->assertFalse($manager->fresh()->hasPermission('customers.view', $retailB));
        $this->actingAs($manager->fresh())
            ->get(route('admin.b2c.dashboard', ['store_id' => $retailB], false))
            ->assertForbidden();
    }

    public function test_explicit_deny_overrides_orders_role_grant_and_navigation_without_editing_shared_role(): void
    {
        $manager = $this->manager('v2-csm-orders-deny@example.test');
        $role = Role::query()->where('code', 'CUSTOMER_SERVICE_MANAGER')->firstOrFail();

        DB::table('user_permission_overrides')->insert([
            'user_id' => $manager->id,
            'permission_id' => Permission::query()->where('code', 'orders.view')->firstOrFail()->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => 'deny',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $manager->forceFill(['has_permission_overrides' => true])->save();

        $this->assertFalse($manager->fresh()->hasPermission('orders.view'));
        $this->assertTrue($manager->fresh()->hasPermission('customers.view'));
        app()->forgetInstance(AdminNavigation::class);
        $keys = collect(app(AdminNavigation::class)->groupsFor($manager->fresh()))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->pluck('key');

        $this->assertNotContains('order_operations', $keys->all());
        $this->assertContains('customer_360', $keys->all());
        $this->actingAs($manager->fresh())
            ->get(route('admin.operations.orders.index', [], false))
            ->assertForbidden();
        $this->assertDatabaseHas('role_user', ['user_id' => $manager->id, 'role_id' => $role->id]);
    }

    public function test_inactive_or_roleless_user_gets_no_service_navigation_or_protected_http(): void
    {
        $inactive = $this->manager('v2-csm-inactive@example.test');
        $inactive->forceFill(['is_active' => false])->save();
        $roleless = User::factory()->create(['is_active' => true]);

        foreach ([$inactive->fresh(), $roleless] as $actor) {
            app()->forgetInstance(AdminNavigation::class);
            $this->assertSame([], app(AdminNavigation::class)->groupsFor($actor));
            $this->actingAs($actor)
                ->get(route('admin.erp.customer-service', [], false))
                ->assertForbidden();
            $this->actingAs($actor)
                ->get(route('admin.security.index', [], false))
                ->assertForbidden();
        }
    }

    private function manager(string $email): User
    {
        $actor = User::factory()->create(['email' => $email, 'locale' => 'en', 'is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'CUSTOMER_SERVICE_MANAGER')->firstOrFail());

        return $actor->fresh();
    }

    private function retailStore(string $code): int
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');

        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'business_profile' => 'RETAIL_STORE',
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\B2bPricingMode;
use App\Services\BuiltInJobRoleCatalog;
use App\Services\WholesalePrincipal;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * V2 #2683 / T122R3: the canonical SALES_MANAGER role has real Wholesale
 * HTTP authority, with no implicit Retail, Finance or Platform grants.
 */
final class V2SalesManagerRoleAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_canonical_sales_manager_has_explicit_seeded_permissions_and_bilingual_business_identity(): void
    {
        $catalog = app(BuiltInJobRoleCatalog::class);
        $definition = $catalog->definition('SALES_MANAGER');
        $this->assertIsArray($definition);
        $this->assertSame('sales', $definition['domain']);
        $this->assertSame('tenant', $definition['business_scope']);
        $this->assertSame('global', $definition['scope']);
        $this->assertSame([], $definition['required_modules']);
        $this->assertNotContains('*', $definition['permissions']);

        $role = Role::query()->where('code', 'SALES_MANAGER')->firstOrFail();
        $this->assertTrue((bool) $role->is_system);
        $this->assertEqualsCanonicalizing(
            $definition['permissions'],
            $role->permissions()->pluck('code')->all(),
        );

        $actor = $this->manager();
        foreach ([
            'b2b.accounts.view', 'b2b.accounts.manage',
            'b2b.pricing.view', 'b2b.pricing.manage',
            'orders.view', 'orders.edit', 'orders.approve',
            'customers.view', 'customers.manage', 'reports.view', 'reports.export',
        ] as $permission) {
            $this->assertTrue($actor->hasPermission($permission), $permission);
        }
        foreach ([
            'security.view', 'roles.manage', 'users.roles.manage',
            'finance.post', 'finance.approve', 'finance.manage',
            'procurement.purchase_orders.issue', 'drivers.b2c.view',
            'deliveries.b2c.execute', 'deliveries.b2b.execute', 'van.login',
        ] as $permission) {
            $this->assertFalse($actor->hasPermission($permission), $permission);
        }
        $this->assertFalse($actor->hasRole('SUPER_ADMIN'));
        $this->assertFalse($actor->isPlatformOwner());

        foreach (['ar' => 'مدير المبيعات', 'en' => 'Sales Manager'] as $locale => $expected) {
            app()->setLocale($locale);
            $presentation = $catalog->presentation('SALES_MANAGER');
            $this->assertSame($expected, $presentation['name']);
            $this->assertNotEmpty($presentation['description']);
            $this->assertNotEmpty($presentation['access_boundaries']);
        }
    }

    public function test_sales_manager_reaches_authorized_wholesale_sales_and_pricing_workflows_in_arabic_and_english(): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $this->setPricingMode($storeId, true);
        $actor = $this->manager();
        $this->assertTrue(app(AdminNavigation::class)->canAccess($actor, 'b2b'));
        $this->assertFalse(app(AdminNavigation::class)->canAccess($actor, 'b2c'));

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();
            app()->forgetInstance(AdminNavigation::class);
            $groups = collect(app(AdminNavigation::class)->groupsFor($actor->fresh()));
            $keys = $groups->flatMap(static fn (array $group): array => $group['children'])
                ->pluck('key')->all();

            foreach (['b2b_dashboard', 'customer_360', 'sales_quotations', 'order_operations', 'reports_center'] as $key) {
                $this->assertContains($key, $keys, $locale.' / '.$key);
            }
            foreach (['b2c_dashboard', 'b2c_customers', 'b2c_orders', 'sidebar_role_access'] as $key) {
                $this->assertNotContains($key, $keys, $locale.' / '.$key);
            }

            $this->actingAs($actor->fresh())
                ->get(route('admin.operations.orders.index', ['channel' => 'b2b'], false))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);
            $this->actingAs($actor->fresh())
                ->get(route('admin.customer-360.index', [], false))
                ->assertOk();
            $this->actingAs($actor->fresh())
                ->get(route('admin.sales-quotations.index', ['store_id' => $storeId], false))
                ->assertOk();

            // P226 is reached through the canonical B2B hub, not a new
            // broad catalog.view grant or duplicate Sidebar pricing entry.
            $this->actingAs($actor->fresh())
                ->get(route('admin.b2b.module', ['module' => 'dashboard'], false))
                ->assertOk()
                ->assertSee('data-v2-pricing-lists-hub', false);
            $this->actingAs($actor->fresh())
                ->get(route('admin.b2b.pricing-approvals', [], false))
                ->assertOk();
            $this->actingAs($actor->fresh())
                ->get(route('admin.reports.index', [], false))
                ->assertOk();
            $this->actingAs($actor->fresh())
                ->get(route('admin.security.index', [], false))
                ->assertForbidden();
        }
    }

    public function test_sales_manager_can_save_authoritative_wholesale_tier_price_but_cannot_manage_platform_security(): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $this->setPricingMode($storeId, true);
        $actor = $this->manager();
        $catalogId = (int) DB::table('catalogs')->insertGetId([
            'store_id' => $storeId,
            'channel' => 'b2b',
            'code' => 'V2-2683-CATALOG',
            'name' => 'V2 Sales Manager Catalog',
            'is_active' => true,
            'is_migration_quarantine' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $unitId = (int) DB::table('units')->insertGetId([
            'code' => 'V2-2683-EA',
            'name' => 'V2 Sales Manager Each',
            'decimal_places' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $productId = (int) DB::table('products')->insertGetId([
            'catalog_id' => $catalogId,
            'unit_id' => $unitId,
            'sku' => 'V2-2683-PRODUCT',
            'name' => 'V2 Sales Manager Product',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('store_products')->insert([
            'store_id' => $storeId,
            'product_id' => $productId,
            'price' => 9.5,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $tierId = (int) DB::table('b2b_price_tiers')->insertGetId([
            'code' => 'V2-2683-TIER',
            'name' => 'Sales Manager Tier',
            'priority' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($actor)
            ->post(route('admin.b2b.pricing.save', [], false), [
                'price_tier_id' => $tierId,
                'product_id' => $productId,
                'unit_price' => 7.25,
                'minimum_quantity' => 3,
                'is_active' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('b2b_price_rules', [
            'store_id' => $storeId,
            'product_id' => $productId,
            'price_tier_id' => $tierId,
            'unit_price' => 7.25,
            'minimum_quantity' => 3,
        ]);
        $this->actingAs($actor)
            ->get(route('admin.b2b.pricing-approvals', [], false))
            ->assertOk()
            ->assertSee('V2 Sales Manager Product');
        $this->actingAs($actor)
            ->get(route('admin.security.index', [], false))
            ->assertForbidden();
    }

    public function test_explicit_orders_deny_removes_only_sales_orders_access_and_direct_http(): void
    {
        $actor = $this->manager();
        $this->deny($actor, 'orders.view');
        $denied = $actor->fresh();

        $this->assertFalse($denied->hasPermission('orders.view'));
        $this->assertTrue($denied->hasPermission('b2b.accounts.view'));
        $this->assertTrue($denied->hasPermission('b2b.pricing.view'));

        app()->forgetInstance(AdminNavigation::class);
        $keys = collect(app(AdminNavigation::class)->groupsFor($denied))
            ->flatMap(static fn (array $group): array => $group['children'])
            ->pluck('key')->all();
        $this->assertNotContains('order_operations', $keys);
        $this->assertNotContains('sales_quotations', $keys);
        $this->assertContains('customer_360', $keys);

        $this->actingAs($denied)
            ->get(route('admin.operations.orders.index', ['channel' => 'b2b'], false))
            ->assertForbidden();
        $this->actingAs($denied)
            ->get(route('admin.sales-quotations.index', [], false))
            ->assertForbidden();
        $this->actingAs($denied)
            ->get(route('admin.customer-360.index', [], false))
            ->assertOk();
    }

    public function test_global_sales_role_never_grants_retail_or_inactive_roleless_access(): void
    {
        $actor = $this->manager();
        $retailId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => 'RETAIL_STORE',
            'code' => 'V2-2683-RETAIL',
            'name' => 'Sales Manager Retail Isolation',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertFalse($actor->hasPermission('orders.view', $retailId));
        $this->actingAs($actor)
            ->get(route('admin.operations.orders.index', ['channel' => 'b2c'], false))
            ->assertForbidden();
        $this->actingAs($actor)
            ->get(route('admin.b2c.module', ['module' => 'customers', 'store_id' => $retailId], false))
            ->assertForbidden();

        // A global override cannot turn a tenant-bound Wholesale role into
        // a Retail store assignment; the same channel boundary still holds.
        $this->allow($actor, 'orders.view');
        $this->assertFalse($actor->fresh()->hasPermission('orders.view', $retailId));
        $this->actingAs($actor->fresh())
            ->get(route('admin.operations.orders.index', ['channel' => 'b2c'], false))
            ->assertForbidden();

        $roleless = User::factory()->create(['is_active' => true]);
        $inactive = $this->manager();
        $inactive->forceFill(['is_active' => false])->save();

        foreach ([$roleless, $inactive->fresh()] as $identity) {
            app()->forgetInstance(AdminNavigation::class);
            $this->assertSame([], app(AdminNavigation::class)->groupsFor($identity));
            $this->actingAs($identity)
                ->get(route('admin.operations.orders.index', ['channel' => 'b2b'], false))
                ->assertForbidden();
            $this->actingAs($identity)
                ->get(route('admin.security.index', [], false))
                ->assertForbidden();
        }
    }

    private function manager(): User
    {
        $actor = User::factory()->create(['locale' => 'en', 'is_active' => true]);
        $actor->roles()->attach(Role::query()->where('code', 'SALES_MANAGER')->firstOrFail());

        return $actor->fresh();
    }

    private function setPricingMode(int $storeId, bool $enabled): void
    {
        DB::table('settings')->updateOrInsert([
            'store_id' => $storeId,
            'key' => B2bPricingMode::SETTING_KEY,
        ], [
            'value' => json_encode($enabled, JSON_THROW_ON_ERROR),
            'is_secret' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function deny(User $actor, string $code): void
    {
        $this->override($actor, $code, 'deny');
    }

    private function allow(User $actor, string $code): void
    {
        $this->override($actor, $code, 'allow');
    }

    private function override(User $actor, string $code, string $effect): void
    {
        DB::table('user_permission_overrides')->insert([
            'user_id' => $actor->id,
            'permission_id' => Permission::query()->where('code', $code)->firstOrFail()->id,
            'scope_key' => 'global',
            'store_id' => null,
            'effect' => $effect,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $actor->forceFill(['has_permission_overrides' => true])->save();
    }
}

<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\AdminSidebarManager;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * P169: business-group placement is derived from registered, authorized
 * navigation. A group label or layout must never become a permission grant.
 */
final class V2BusinessDomainSidebarGroupingTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private const DOMAIN_ITEMS = [
        'commercial_sales_control' => 'catalog',
        'catalog_management' => 'catalog',
        'b2b_inventory' => 'catalog',
        'b2c_inventory' => 'catalog',
        'order_operations' => 'operations',
        'b2c_incoming_orders' => 'operations',
        'treasury_master' => 'finance',
        'erp_finance' => 'finance',
        'administration_hub' => 'administration',
        'sidebar_role_access' => 'administration',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_sales_orders_inventory_finance_and_administration_have_single_canonical_business_owners(): void
    {
        $registry = app(AdminSidebarManager::class)->registry();
        $layout = app(AdminSidebarManager::class)->layoutData();

        foreach (self::DOMAIN_ITEMS as $item => $group) {
            $this->assertArrayHasKey($group, $registry['groups']);
            $this->assertArrayHasKey($item, $registry['items']);
            $this->assertSame($group, $registry['items'][$item]['group'], $item.' registered domain');
            $this->assertSame($group, $layout['items'][$item]['group_key'], $item.' default placement');
        }

        foreach (['catalog', 'procurement', 'warehouses', 'operations', 'finance', 'administration'] as $group) {
            $label = $registry['groups'][$group]['label'];
            $this->assertTrue(Lang::hasForLocale($label, 'ar'), $group.' Arabic group label');
            $this->assertTrue(Lang::hasForLocale($label, 'en'), $group.' English group label');
        }

        // P238 Procurement and P253 Warehouses each have a single authorized
        // business owner; neither belongs to the Catalog grouping.
        $this->assertSame('procurement', $registry['items']['erp_procurement']['group']);
        $this->assertSame('warehouses', $registry['items']['erp_wms']['group']);
        $this->assertSame('finance', $registry['items']['erp_treasury']['group']);
    }

    public function test_wholesale_sidebar_renders_only_authorized_business_groups_and_real_order_routes(): void
    {
        $actor = $this->globalUser('B2B_ADMIN');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $groups = app(AdminNavigation::class)->groupsFor($actor->fresh());
            $this->assertSame('catalog', $this->groupOf($groups, 'catalog_management'));
            $this->assertSame('operations', $this->groupOf($groups, 'order_operations'));
            $this->assertNull($this->groupOf($groups, 'b2c_inventory'));

            $html = (string) $this->actingAs($actor->fresh())
                ->get(route('admin.b2b.dashboard'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->getContent();

            $this->assertStringContainsString('data-nav-group="catalog"', $html);
            $this->assertStringContainsString('data-nav-group="operations"', $html);
            $this->assertStringContainsString('data-nav-item="order_operations"', $html);
            $this->assertStringNotContainsString('data-nav-item="b2c_inventory"', $html);
            $this->actingAs($actor->fresh())
                ->get(route('admin.operations.orders.index'))
                ->assertOk();
        }
    }

    public function test_retail_business_groups_keep_own_store_and_deny_wholesale_and_foreign_store_http(): void
    {
        $own = $this->retailStore('V2-P169-OWN');
        $foreign = $this->retailStore('V2-P169-FOREIGN');
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $own,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $groups = app(AdminNavigation::class)->groupsFor($actor->fresh());
            $this->assertSame('catalog', $this->groupOf($groups, 'b2c_inventory'));
            // Retail incoming orders may be hidden by the module's real
            // readiness/route guard; a category label must not resurrect it.
            $this->assertContains(
                $this->groupOf($groups, 'b2c_incoming_orders'),
                [null, 'operations'],
                'An authorized Retail incoming-orders link belongs only to Operations.',
            );
            $this->assertNull($this->groupOf($groups, 'b2b_inventory'));
            $this->assertNull($this->groupOf($groups, 'erp_finance'));

            $html = (string) $this->actingAs($actor->fresh())
                ->get(route('admin.b2c.module', ['module' => 'dashboard', 'store_id' => $own]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->getContent();

            $this->assertStringContainsString('data-nav-item="b2c_inventory"', $html);
            $this->assertStringNotContainsString('data-nav-item="b2b_inventory"', $html);
            $this->actingAs($actor->fresh())
                ->get(route('admin.b2b.dashboard'))
                ->assertForbidden();
            $this->actingAs($actor->fresh())
                ->get(route('admin.b2c.module', ['module' => 'dashboard', 'store_id' => $foreign]))
                ->assertNotFound();
        }
    }

    public function test_roleless_and_inactive_users_cannot_inherit_business_groups_from_labels(): void
    {
        $roleless = User::factory()->create(['is_active' => true]);
        $inactive = $this->globalUser('B2B_ADMIN');
        $inactive->forceFill(['is_active' => false])->save();

        foreach ([$roleless, $inactive] as $actor) {
            $this->assertSame([], app(AdminNavigation::class)->groupsFor($actor->fresh()));
            $this->actingAs($actor->fresh())->get(route('admin.b2b.dashboard'))->assertForbidden();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     */
    private function groupOf(array $groups, string $itemKey): ?string
    {
        foreach ($groups as $group) {
            foreach ($group['children'] as $item) {
                if ($item['key'] === $itemKey) {
                    return $group['key'];
                }
            }
        }

        return null;
    }

    private function globalUser(string $roleCode): User
    {
        $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Enterprise\Modules\ModuleRegistry;
use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\AdminSidebarManager;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * V2 P118: the wholesale ERP business journey keeps one deterministic order
 * in its registry, server-side Sidebar projection and real ERP Center.
 */
final class V2WholesaleErpNavigationSequenceTest extends TestCase
{
    use RefreshDatabase;

    private const NAVIGATION_KEYS = [
        'erp_center',
        'erp_customer_service',
        'erp_procurement',
        'erp_wms',
        'erp_finance',
        'erp_treasury',
        'erp_bi',
        'erp_my_work',
    ];

    /** Sidebar presentation groups; ERP Center cards preserve P118 sequence. */
    private const SIDEBAR_GROUPS = [
        'erp_center' => 'erp',
        'erp_customer_service' => 'erp',
        'erp_procurement' => 'catalog',
        'erp_wms' => 'catalog',
        'erp_finance' => 'finance',
        'erp_treasury' => 'finance',
        'erp_bi' => 'analytics',
        'erp_my_work' => 'erp',
    ];

    private const CARD_KEYS = [
        'customer_service',
        'procurement',
        'wms',
        'finance',
        'treasury',
        'bi',
        'my_work',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        // The Sidebar now requires exact-version operational certification.
        // Exercise the intentionally ready business-journey order only in this
        // fixture: production defaults remain uncertified until approved.
        foreach (['procurement', 'inventory_wms', 'finance', 'treasury', 'enterprise_bi'] as $code) {
            config()->set(
                'enterprise_module_readiness.certified_versions.'.$code,
                app(ModuleRegistry::class)->manifest($code)->version,
            );
        }
    }

    public function test_registered_sidebar_defaults_group_erp_modules_by_business_domain(): void
    {
        $registry = app(AdminSidebarManager::class)->registry();
        $layout = app(AdminSidebarManager::class)->layoutData();
        $groupOrders = [];

        foreach (self::SIDEBAR_GROUPS as $key => $group) {
            $this->assertSame($group, $registry['items'][$key]['group']);
            $this->assertSame($group, $layout['items'][$key]['group_key']);
            $order = (int) $registry['items'][$key]['order'];
            $this->assertSame($order, $layout['items'][$key]['sort_order']);
            $this->assertNotContains($order, $groupOrders[$group] ?? [], $group.' duplicate sort order');
            $groupOrders[$group][] = $order;
        }

        $this->assertSame(self::NAVIGATION_KEYS, array_keys(self::SIDEBAR_GROUPS));
    }

    public function test_super_admin_gets_the_same_authorized_erp_sequence_in_arabic_and_english_on_real_http(): void
    {
        $actor = $this->globalRoleUser('SUPER_ADMIN');

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            $actor->forceFill(['locale' => $locale])->save();
            app()->setLocale($locale);

            // ERP Center's local bootstrap enables disposable test modules
            // and their platform entitlements before projecting the Sidebar.
            $response = $this->actingAs($actor)
                ->get(route('admin.erp.index'))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false);

            $this->assertSame(
                self::CARD_KEYS,
                collect($response->viewData('cards'))->pluck('key')->all(),
                $locale.' ERP Center card order',
            );

            $response->assertSeeInOrder(array_map(
                static fn (string $key): string => 'data-erp-key="'.$key.'"',
                self::CARD_KEYS,
            ), false);

            app()->forgetInstance(AdminNavigation::class);
            $groups = collect(app(AdminNavigation::class)->groupsFor($actor));
            $erp = $groups->firstWhere('key', 'erp');

            $this->assertIsArray($erp);
            $this->assertSame(
                ['erp_center', 'erp_customer_service', 'erp_my_work'],
                collect($erp['children'])->pluck('key')->all(),
                $locale.' ERP hub shortcuts (Center cards retain the full sequence)',
            );

            foreach (self::SIDEBAR_GROUPS as $key => $expectedGroup) {
                $found = [];
                foreach ($groups as $group) {
                    foreach ($group['children'] as $item) {
                        if ($item['key'] === $key) {
                            $found[] = ['group' => $group['key'], 'route' => $item['route']];
                        }
                    }
                }
                $this->assertCount(1, $found, $locale.' '.$key.' single authorized destination');
                $this->assertSame($expectedGroup, $found[0]['group']);
                $this->assertTrue(Route::has($found[0]['route']));
            }
        }
    }

    public function test_retail_store_role_does_not_inherit_wholesale_erp_group_or_global_permissions(): void
    {
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => 'V2-P118-RETAIL',
            'name' => 'V2 P118 Retail',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $retail = User::factory()->create(['is_active' => true, 'locale' => 'ar']);
        DB::table('user_store_roles')->insert([
            'user_id' => $retail->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertFalse($retail->hasPermission('procurement.suppliers.view'));

        $keys = collect(app(AdminNavigation::class)->groupsFor($retail))
            ->pluck('key')->all();
        $this->assertNotContains('erp', $keys);
    }

    public function test_custom_layout_cannot_promote_an_unauthorized_or_disabled_module_into_the_erp_sidebar(): void
    {
        $actor = $this->globalRoleUser('FINANCE');

        // The default inventory/procurement state is not guaranteed enabled
        // for this actor. A configured layout remains a presentation filter,
        // not a capability or entitlement grant.
        DB::table('admin_sidebar_items')->insert([
            'item_key' => 'erp_procurement',
            'group_key' => 'erp',
            'parent_key' => null,
            'sort_order' => 1,
            'is_enabled' => true,
            'label_ar' => 'المشتريات',
            'label_en' => 'Procurement',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $group = collect(app(AdminNavigation::class)->groupsFor($actor))
            ->firstWhere('key', 'erp');

        $this->assertIsArray($group);
        $this->assertNotContains('erp_procurement', collect($group['children'])->pluck('key')->all());
        $this->assertFalse($actor->hasPermission('procurement.suppliers.view'));
    }

    private function globalRoleUser(string $code): User
    {
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'en']);
        $actor->roles()->attach(Role::query()->where('code', $code)->firstOrFail()->id);

        return $actor;
    }
}

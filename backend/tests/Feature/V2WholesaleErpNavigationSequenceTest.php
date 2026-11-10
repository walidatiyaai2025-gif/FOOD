<?php

namespace Tests\Feature;

use App\Enterprise\Entitlements\EntitlementScope;
use App\Enterprise\Entitlements\ModuleEntitlementManager;
use App\Models\Role;
use App\Models\User;
use App\Services\AdminSidebarManager;
use App\Support\AdminNavigation;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
    }

    public function test_registered_default_positions_are_unique_and_follow_wholesale_business_sequence(): void
    {
        $registry = app(AdminSidebarManager::class)->registry();
        $configured = (array) $registry['items'];

        $actual = array_map(
            static fn (string $key): int => (int) $configured[$key]['order'],
            self::NAVIGATION_KEYS,
        );

        $this->assertSame([10, 20, 30, 40, 50, 60, 70, 80], array_values($actual));
        $this->assertCount(count(array_unique($actual)), $actual);
        $this->assertTrue(collect(self::NAVIGATION_KEYS)
            ->every(static fn (string $key): bool => $configured[$key]['group'] === 'erp'));

        $layout = app(AdminSidebarManager::class)->layoutData();
        foreach (self::NAVIGATION_KEYS as $index => $key) {
            $this->assertSame(($index + 1) * 10, $layout['items'][$key]['sort_order']);
        }
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
            $group = collect(app(AdminNavigation::class)->groupsFor($actor))
                ->firstWhere('key', 'erp');

            $this->assertIsArray($group);
            $this->assertSame(
                self::NAVIGATION_KEYS,
                collect($group['children'])->pluck('key')->all(),
                $locale.' Sidebar order',
            );

            foreach ($group['children'] as $item) {
                $this->assertTrue(
                    \Illuminate\Support\Facades\Route::has($item['route']),
                    $item['key'].' must resolve to a registered destination',
                );
            }
        }
    }

    public function test_retail_store_role_does_not_inherit_wholesale_erp_group_or_global_permissions(): void
    {
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => \App\Enums\StoreBusinessProfile::RETAIL_STORE->value,
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

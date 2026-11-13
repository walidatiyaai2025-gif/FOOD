<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\B2bPricingMode;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class V2SalesContractsPricingNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_authorized_wholesale_sales_page_reuses_scoped_pricing_destinations_in_english(): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $actor = $this->wholesaleActor('v2-p195-wholesale@example.test', 'en');
        $this->enableWholesalePricingTiers($storeId);

        $this->actingAs($actor)
            ->get(route('admin.sales-quotations.index', ['store_id' => $storeId]))
            ->assertOk()
            ->assertViewHas('pricingControlUrl', route('admin.commercial.sales-control', ['store_id' => $storeId]))
            ->assertViewHas('priceListsUrl', route('admin.b2b.pricing-approvals'))
            ->assertSee('data-sales-pricing-nav', false)
            ->assertSee('data-pricing-control-link', false)
            ->assertSee('data-pricing-lists-link', false)
            ->assertSee('Contracts and pricing')
            ->assertSee('Quotations are not executed sales contracts');

        $this->assertSame(0, DB::table('sales_quotations')->count());
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_disabled_wholesale_tiers_hide_only_the_tiers_link_and_preserve_quotations_in_arabic(): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $actor = $this->wholesaleActor('v2-p195-wholesale-ar@example.test', 'ar');

        $this->actingAs($actor)
            ->get(route('admin.sales-quotations.index', ['store_id' => $storeId]))
            ->assertOk()
            ->assertViewHas('pricingControlUrl', route('admin.commercial.sales-control', ['store_id' => $storeId]))
            ->assertViewHas('priceListsUrl', null)
            ->assertSee('data-sales-pricing-nav', false)
            ->assertDontSee('data-pricing-lists-link', false)
            ->assertSee('عروض الأسعار')
            ->assertSee('عروض الأسعار ليست عقود بيع معتمدة');

        $this->assertSame(0, DB::table('sales_quotations')->count());
    }

    public function test_retail_store_actor_never_receives_wholesale_price_links_or_cross_store_access(): void
    {
        $storeId = $this->retailStore('V2-P195-RETAIL');
        $wholesaleId = app(WholesalePrincipal::class)->storeId();
        $actor = $this->retailActor('v2-p195-retail@example.test', $storeId, 'en');
        $this->enableWholesalePricingTiers($wholesaleId);

        $this->actingAs($actor)
            ->get(route('admin.sales-quotations.index', ['store_id' => $storeId]))
            ->assertOk()
            ->assertViewHas('pricingControlUrl', null)
            ->assertViewHas('priceListsUrl', null)
            ->assertSee('data-sales-pricing-nav', false)
            ->assertDontSee('data-pricing-control-link', false)
            ->assertDontSee('data-pricing-lists-link', false);

        $this->actingAs($actor)
            ->get(route('admin.sales-quotations.index', ['store_id' => $wholesaleId]))
            ->assertNotFound();

        $this->assertSame(0, DB::table('sales_quotations')->count());
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_actor_without_order_view_permission_cannot_open_sales_pricing_entry(): void
    {
        $storeId = $this->retailStore('V2-P195-DENIED');
        $actor = User::factory()->create([
            'email' => 'v2-p195-denied@example.test',
            'is_active' => true,
            'locale' => 'en',
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'RETAIL_INVENTORY')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($actor)
            ->get(route('admin.sales-quotations.index', ['store_id' => $storeId]))
            ->assertForbidden();

        $this->assertSame(0, DB::table('sales_quotations')->count());
    }

    private function wholesaleActor(string $email, string $locale): User
    {
        $actor = User::factory()->create([
            'email' => $email,
            'is_active' => true,
            'locale' => $locale,
        ]);
        $actor->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        return $actor;
    }

    private function retailActor(string $email, int $storeId, string $locale): User
    {
        $actor = User::factory()->create([
            'email' => $email,
            'is_active' => true,
            'locale' => $locale,
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $actor->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $actor;
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => 'RETAIL_STORE',
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function enableWholesalePricingTiers(int $storeId): void
    {
        DB::table('settings')->updateOrInsert(
            ['store_id' => $storeId, 'key' => B2bPricingMode::SETTING_KEY],
            [
                'value' => 'true',
                'is_secret' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
}

<?php

namespace Tests\Feature;

use App\Enums\StoreBusinessProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #2559 G009: two existing Commercial page destinations use one native,
 * server-authorized horizontal rail; pricing and policy forms remain atomic.
 */
final class V2CommercialSalesControlHorizontalTabsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.mfa.enforce_privileged', false);
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_wholesale_commercial_page_has_one_scrollable_current_tab_in_arabic_and_english_without_get_mutations(): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $this->assertGreaterThan(0, $storeId);
        $actor = User::factory()->create(['is_active' => true, 'locale' => 'ar']);
        $actor->roles()->attach(Role::query()->where('code', 'B2B_ADMIN')->firstOrFail());

        $blade = (string) file_get_contents(resource_path('views/admin/commercial-dashboard.blade.php'));
        $this->assertStringContainsString('.commercial-tabs{display:flex;gap:8px;flex-wrap:nowrap;', $blade);
        $this->assertStringContainsString('overflow-x:auto;', $blade);
        $this->assertStringContainsString('.commercial-tabs a:focus-visible{outline:', $blade);
        $this->assertStringNotContainsString('.commercial-tabs{display:flex;gap:8px;flex-wrap:wrap;', $blade);

        $policiesBefore = DB::table('product_commercial_policies')->count();
        $offersBefore = DB::table('flash_offers')->count();

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            app()->setLocale($locale);
            $actor->forceFill(['locale' => $locale])->save();

            $response = $this->actingAs($actor->fresh())
                ->get(route('admin.commercial.sales-control', ['store_id' => $storeId]))
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee('data-commercial-page="sales-control"', false);

            $html = html_entity_decode($response->getContent(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $this->assertSame(1, preg_match(
                '/<nav class="commercial-tabs foodex-tabs foodex-tabs--horizontal"[^>]*>(.*?)<\/nav>/s',
                $html,
                $nav,
            ));
            $this->assertSame(1, substr_count($nav[1], 'aria-current="page"'));
            $this->assertStringContainsString('store_id='.$storeId, $nav[1]);
            $this->assertStringContainsString('commercial/sales-control', $nav[1]);
            $this->assertStringContainsString('commercial/flash-offers', $nav[1]);
            $this->assertStringNotContainsString('role="tablist"', $nav[0]);
            $this->assertStringNotContainsString('_token=', $nav[1]);
            $this->assertStringNotContainsString('_operation_key=', $nav[1]);
        }

        $this->assertSame($policiesBefore, DB::table('product_commercial_policies')->count());
        $this->assertSame($offersBefore, DB::table('flash_offers')->count());
    }

    public function test_roleless_and_retail_actors_cannot_access_wholesale_commercial_tabs(): void
    {
        $roleless = User::factory()->create(['is_active' => true]);
        $this->actingAs($roleless)
            ->get(route('admin.commercial.sales-control'))
            ->assertForbidden();

        // An actual assigned retail role and B2C store cannot inherit the
        // B2B Sales Control route through a tab/deep-link switch.
        $retailStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'business_profile' => StoreBusinessProfile::RETAIL_STORE->value,
            'code' => 'P229-COMMERCIAL-UX-RETAIL',
            'name' => 'Retail Commerce scope',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $retail = User::factory()->create(['is_active' => true]);
        DB::table('user_store_roles')->insert([
            'user_id' => $retail->id,
            'store_id' => $retailStoreId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->firstOrFail()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($retail)
            ->get(route('admin.commercial.sales-control', ['store_id' => $retailStoreId]))
            ->assertNotFound();
    }
}

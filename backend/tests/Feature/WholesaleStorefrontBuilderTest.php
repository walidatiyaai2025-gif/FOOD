<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\WholesalePrincipal;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WholesaleStorefrontBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        Storage::fake('public');
    }

    public function test_b2b_admin_can_manage_wholesale_storefront_and_mobile_payload_reflects_it(): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $admin = $this->roleUser('B2B_ADMIN', 'wholesale-builder@example.test');

        $this->actingAs($admin)
            ->get(route('admin.b2b.module', ['module' => 'storefront']))
            ->assertOk()
            ->assertSee('Wholesale branding &amp; theme', false)
            ->assertSee('Home sections')
            ->assertSee('Wholesale banners');

        $this->actingAs($admin)->put(route('admin.b2b.storefront.settings'), [
            'store_id' => $storeId,
            'theme_code' => 'wholesale_b2b',
            'primary_color' => '#6A2CA0',
            'primary_dark_color' => '#32144F',
            'accent_color' => '#C79AF2',
            'background_color' => '#FAF7FD',
            'header_address' => 'Cairo & Alexandria',
            'brand_title_ar' => 'فودكس جملة',
            'brand_title_en' => 'FOODEX Wholesale',
            'brand_subtitle_ar' => 'أفضل أسعار التوريد',
            'brand_subtitle_en' => 'Best supply prices',
            'hero_cta_ar' => 'ابدأ الطلب',
            'hero_cta_en' => 'Start order',
            'logo' => UploadedFile::fake()->image('wholesale.png', 512, 512),
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->post(route('admin.b2b.storefront.sections.store'), [
            'store_id' => $storeId,
            'section_key' => 'best_sellers',
            'section_type' => 'best_sellers',
            'title_ar' => 'الأكثر مبيعاً',
            'title_en' => 'Best sellers',
            'sort_order' => 35,
            'config_json' => '{"limit":8}',
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $buyer = User::query()->create([
            'name' => 'Wholesale Buyer',
            'email' => 'mobile-wholesale@example.test',
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $customerId = (int) DB::table('b2b_customers')->insertGetId([
            'user_id' => $buyer->id,
            'name' => 'Wholesale Buyer',
            'email' => $buyer->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $tierId = (int) DB::table('b2b_price_tiers')->where('code', 'STANDARD')->value('id');
        DB::table('b2b_accounts')->insert([
            'b2b_customer_id' => $customerId,
            'company_name' => 'Wholesale Buyer Co',
            'price_tier_id' => $tierId,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($buyer);

        $this->getJson('/api/v1/b2b/stores/'.$storeId.'/storefront')
            ->assertOk()
            ->assertJsonPath('store.channel', 'b2b')
            ->assertJsonPath('theme.primary', '#6A2CA0')
            ->assertJsonPath('branding.address', 'Cairo & Alexandria')
            ->assertJsonPath('branding.custom.brand_title_ar', 'فودكس جملة')
            ->assertJsonPath('branding.custom.hero_cta_ar', 'ابدأ الطلب')
            ->assertJsonFragment([
                'key' => 'best_sellers',
                'type' => 'best_sellers',
                'title_ar' => 'الأكثر مبيعاً',
            ]);
    }

    public function test_retail_admin_cannot_mutate_wholesale_storefront(): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $retailStoreId = (int) DB::table('stores')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->where('store_types.code', 'B2C')
            ->value('stores.id');

        $user = $this->roleUser('B2C_STORE_ADMIN', 'retail-builder-denied@example.test');
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $retailStoreId,
            'role_id' => (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)->put(route('admin.b2b.storefront.settings'), [
            'store_id' => $storeId,
            'theme_code' => 'wholesale_b2b',
            'primary_color' => '#5D2A91',
        ])->assertForbidden();
    }

    private function roleUser(string $roleCode, string $email): User
    {
        $user = User::query()->create([
            'name' => $roleCode,
            'email' => $email,
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $user->roles()->attach(Role::query()->where('code', $roleCode)->firstOrFail());

        return $user;
    }
}

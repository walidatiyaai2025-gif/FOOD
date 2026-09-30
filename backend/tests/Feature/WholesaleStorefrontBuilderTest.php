<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\StorefrontRevision;
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

    public function test_b2b_storefront_editor_is_draft_first_and_mobile_changes_only_after_publish(): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $admin = $this->roleUser('B2B_ADMIN', 'wholesale-builder@example.test');

        $this->actingAs($admin)
            ->get(route('admin.b2b.module', ['module' => 'storefront']))
            ->assertOk()
            ->assertSee('Wholesale branding &amp; theme', false)
            ->assertSee('Save to Draft')
            ->assertSee('Open real app preview');

        $buyer = User::query()->create([
            'name' => 'Wholesale Buyer',
            'email' => 'mobile-wholesale@example.test',
            'password' => 'password',
            'locale' => 'ar',
            'is_active' => true,
        ]);
        $legacyCustomerId = (int) DB::table('customers')->insertGetId([
            'user_id' => $buyer->id,
            'type' => 'b2b',
            'name' => 'Wholesale Buyer',
            'email' => $buyer->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customerId = (int) DB::table('b2b_customers')->insertGetId([
            'legacy_customer_id' => $legacyCustomerId,
            'user_id' => $buyer->id,
            'name' => 'Wholesale Buyer',
            'email' => $buyer->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $tierId = (int) DB::table('b2b_price_tiers')->where('code', 'STANDARD')->value('id');
        DB::table('b2b_accounts')->insert([
            'customer_id' => $legacyCustomerId,
            'b2b_customer_id' => $customerId,
            'company_name' => 'Wholesale Buyer Co',
            'price_tier_id' => $tierId,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($buyer);
        $before = $this->getJson('/api/v1/b2b/stores/'.$storeId.'/storefront')->assertOk();
        $this->assertNotSame('#6A2CA0', $before->json('theme.primary'));

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

        $this->assertDatabaseMissing('storefront_settings', [
            'store_id' => $storeId,
            'primary_color' => '#6A2CA0',
        ]);
        $this->assertDatabaseMissing('storefront_sections', [
            'store_id' => $storeId,
            'section_key' => 'best_sellers',
        ]);

        $draft = StorefrontRevision::query()
            ->where('store_id', $storeId)
            ->where('channel', 'b2b')
            ->where('status', 'draft')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('#6A2CA0', $draft->payload['settings']['primary_color']);
        $this->assertSame('FOODEX Wholesale', $draft->payload['settings']['branding']['brand_title_en']);
        $this->assertNotNull(collect($draft->payload['sections'])->firstWhere('key', 'best_sellers'));
        $this->assertStringStartsWith('storage/stores/'.$storeId.'/branding/', (string) $draft->payload['store']['logo_path']);

        Sanctum::actingAs($buyer);
        $this->getJson('/api/v1/b2b/stores/'.$storeId.'/storefront')
            ->assertOk()
            ->assertJsonMissing(['primary' => '#6A2CA0'])
            ->assertJsonMissing(['title_en' => 'Best sellers']);

        $this->actingAs($admin)->post(route('admin.b2b.storefront.publish'), [
            'store_id' => $storeId,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('storefront_settings', [
            'store_id' => $storeId,
            'primary_color' => '#6A2CA0',
            'header_address' => 'Cairo & Alexandria',
        ]);
        $this->assertDatabaseHas('storefront_sections', [
            'store_id' => $storeId,
            'section_key' => 'best_sellers',
            'title_en' => 'Best sellers',
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
        $retailTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $retailStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $retailTypeId,
            'code' => 'STOREFRONT-RETAIL-DENIED',
            'name' => 'Retail Denied',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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
        ])->assertNotFound();

        $this->assertDatabaseMissing('storefront_revisions', [
            'store_id' => $storeId,
            'channel' => 'b2b',
            'status' => 'draft',
        ]);
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

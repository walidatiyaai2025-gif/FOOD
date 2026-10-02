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
            ->assertSee('Save Draft')
            ->assertSee('Open real app preview')
            ->assertSee('channel=b2b', false)
            ->assertSee('store_id='.$storeId, false)
            ->assertSee('mode=draft', false);

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

        $this->actingAs($admin)->post(route('admin.b2b.storefront.banners.store'), [
            'store_id' => $storeId,
            'title' => 'Wholesale Draft Hero',
            'banner_image' => UploadedFile::fake()->image('wholesale-hero.webp', 1200, 420),
            'sort_order' => 5,
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
        $this->assertDatabaseMissing('banners', [
            'store_id' => $storeId,
            'title' => 'Wholesale Draft Hero',
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
        $draftBanner = collect($draft->payload['banners'])->firstWhere('title', 'Wholesale Draft Hero');
        $this->assertNotNull($draftBanner);
        $this->assertNotEmpty($draftBanner['editor_id']);
        $this->assertStringStartsWith('storage/stores/'.$storeId.'/branding/', (string) $draft->payload['store']['logo_path']);
        $this->assertStringStartsWith('storage/banners/'.$storeId.'/', (string) $draftBanner['image_path']);

        Sanctum::actingAs($buyer);
        $this->getJson('/api/v1/b2b/stores/'.$storeId.'/storefront')
            ->assertOk()
            ->assertJsonMissing(['primary' => '#6A2CA0'])
            ->assertJsonMissing(['title_en' => 'Best sellers'])
            ->assertJsonMissing(['title' => 'Wholesale Draft Hero']);

        $draftChecksum = $draft->checksum;

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
        $this->assertDatabaseHas('banners', [
            'store_id' => $storeId,
            'title' => 'Wholesale Draft Hero',
        ]);

        $draft->refresh();
        $this->assertSame('published', $draft->status);
        $this->assertSame($draftChecksum, $draft->checksum);

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
            ])
            ->assertJsonFragment([
                'title' => 'Wholesale Draft Hero',
                'sort_order' => 5,
            ]);
    }

    public function test_wholesale_storefront_can_publish_platform_retail_store_placement(): void
    {
        $platformStoreId = app(WholesalePrincipal::class)->storeId();
        $retailTypeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $retailStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $retailTypeId,
            'code' => 'PLATFORM-PLACEMENT-RETAIL',
            'name' => 'Retail Placement Store',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $secondRetailStoreId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $retailTypeId,
            'code' => 'PLATFORM-PLACEMENT-RETAIL-2',
            'name' => 'Retail Placement Store Two',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $admin = $this->roleUser('B2B_ADMIN', 'wholesale-placement@example.test');
        $startsAt = now()->subHour()->format('Y-m-d\TH:i');
        $endsAt = now()->addHour()->format('Y-m-d\TH:i');

        $this->actingAs($admin)
            ->get(route('admin.b2b.module', ['module' => 'storefront']))
            ->assertOk()
            ->assertSee('Retail Store · Retail Placement Store')
            ->assertSee('Retail Store · Retail Placement Store Two')
            ->assertSee('Starts at')
            ->assertSee('Ends at');

        $this->actingAs($admin)->post(route('admin.b2b.storefront.banners.store'), [
            'store_id' => $platformStoreId,
            'title' => 'Retail Merchant Placement',
            'banner_image' => UploadedFile::fake()->image('retail-placement.webp', 1200, 420),
            'target_ref' => 'retail_store:'.$retailStoreId,
            'sort_order' => 4,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->post(route('admin.b2b.storefront.banners.store'), [
            'store_id' => $platformStoreId,
            'title' => 'Retail Merchant Placement Two',
            'banner_image' => UploadedFile::fake()->image('retail-placement-two.webp', 1200, 420),
            'target_ref' => 'retail_store:'.$secondRetailStoreId,
            'sort_order' => 2,
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->post(route('admin.b2b.storefront.banners.store'), [
            'store_id' => $platformStoreId,
            'title' => 'Future Retail Placement',
            'banner_image' => UploadedFile::fake()->image('retail-placement-future.webp', 1200, 420),
            'target_ref' => 'retail_store:'.$secondRetailStoreId,
            'sort_order' => 1,
            'starts_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'ends_at' => now()->addDays(2)->format('Y-m-d\TH:i'),
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $draft = StorefrontRevision::query()
            ->where('store_id', $platformStoreId)
            ->where('channel', 'b2b')
            ->where('status', 'draft')
            ->latest('id')
            ->firstOrFail();
        $placement = collect($draft->payload['banners'])
            ->firstWhere('title', 'Retail Merchant Placement');

        $this->assertNotNull($placement);
        $this->assertSame('retail_store', $placement['target_type']);
        $this->assertSame($retailStoreId, (int) $placement['target_id']);
        $this->assertSame('/retail/'.$retailStoreId.'/home', $placement['target_url']);
        $this->assertSame($startsAt, $placement['starts_at']);
        $this->assertSame($endsAt, $placement['ends_at']);

        $this->getJson('/api/v1/platform/storefront')
            ->assertOk()
            ->assertJsonMissing(['title' => 'Retail Merchant Placement'])
            ->assertJsonMissing(['title' => 'Retail Merchant Placement Two'])
            ->assertJsonMissing(['title' => 'Future Retail Placement']);

        $this->actingAs($admin)->post(route('admin.b2b.storefront.publish'), [
            'store_id' => $platformStoreId,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('banners', [
            'store_id' => $platformStoreId,
            'title' => 'Retail Merchant Placement',
            'target_type' => 'retail_store',
            'target_id' => $retailStoreId,
            'target_url' => '/retail/'.$retailStoreId.'/home',
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/platform/storefront')
            ->assertOk()
            ->assertJsonPath('retail_banners.0.store_id', $secondRetailStoreId)
            ->assertJsonPath('retail_banners.0.title', 'Retail Merchant Placement Two')
            ->assertJsonPath('retail_banners.0.sort_order', 2)
            ->assertJsonPath('retail_banners.1.store_id', $retailStoreId)
            ->assertJsonPath('retail_banners.1.title', 'Retail Merchant Placement')
            ->assertJsonPath('retail_banners.1.placement_scope', 'platform_retail_store')
            ->assertJsonPath('retail_banners.1.target_type', 'retail_store')
            ->assertJsonPath('retail_banners.1.target_id', $retailStoreId)
            ->assertJsonMissing(['title' => 'Future Retail Placement'])
            ->assertJsonCount(2, 'retail_banners')
            ->assertJsonCount(0, 'banners');

        $this->getJson('/api/v1/stores/'.$retailStoreId.'/storefront')
            ->assertOk()
            ->assertJsonCount(0, 'banners');

        $this->getJson('/api/v1/wholesale/stores/'.$platformStoreId.'/storefront')
            ->assertOk()
            ->assertJsonMissing(['title' => 'Retail Merchant Placement'])
            ->assertJsonMissing(['title' => 'Retail Merchant Placement Two']);
    }

    public function test_wholesale_discard_restores_draft_from_published_without_changing_live(): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $admin = $this->roleUser('B2B_ADMIN', 'wholesale-discard@example.test');

        DB::table('storefront_settings')->updateOrInsert(
            ['store_id' => $storeId],
            [
                'theme_code' => 'wholesale_b2b',
                'primary_color' => '#5D2A91',
                'primary_dark_color' => '#35195E',
                'accent_color' => '#B983F0',
                'background_color' => '#FBFAFD',
                'header_address' => 'Published wholesale address',
                'branding' => json_encode(['brand_title_en' => 'Published Wholesale'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $this->actingAs($admin)->put(route('admin.b2b.storefront.settings'), [
            'store_id' => $storeId,
            'theme_code' => 'wholesale_b2b',
            'primary_color' => '#712FA8',
            'primary_dark_color' => '#35195E',
            'accent_color' => '#B983F0',
            'background_color' => '#FBFAFD',
            'header_address' => 'Draft wholesale address',
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            '#5D2A91',
            DB::table('storefront_settings')->where('store_id', $storeId)->value('primary_color'),
        );

        $this->actingAs($admin)->post(route('admin.b2b.storefront.discard'), [
            'store_id' => $storeId,
        ])->assertSessionHasNoErrors();

        $draft = StorefrontRevision::query()
            ->where('store_id', $storeId)
            ->where('channel', 'b2b')
            ->where('status', 'draft')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('#5D2A91', $draft->payload['settings']['primary_color']);
        $this->assertSame('Published wholesale address', $draft->payload['settings']['header_address']);
        $this->assertSame(
            '#5D2A91',
            DB::table('storefront_settings')->where('store_id', $storeId)->value('primary_color'),
        );
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'storefront.revision.draft_discarded',
            'user_id' => $admin->id,
            'store_id' => $storeId,
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

<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StorefrontAdminBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        Storage::fake('public');
    }

    public function test_retail_store_admin_can_manage_branding_sections_and_service_zones(): void
    {
        $storeId = $this->retailStore('BUILDER-A');
        $admin = $this->storeAdmin($storeId, 'builder-a@example.test');

        $this->actingAs($admin)
            ->get(route('admin.b2c.module', ['module' => 'storefront', 'store_id' => $storeId]))
            ->assertOk()
            ->assertSee('Store branding &amp; theme', false)
            ->assertSee('Home layout sections')
            ->assertSee('Service zones')
            ->assertSee('Storefront banners');

        $this->actingAs($admin)->put(route('admin.b2c.storefront.settings'), [
            'store_id' => $storeId,
            'theme_code' => 'retail_pharmacy',
            'primary_color' => '#0A8DDA',
            'primary_dark_color' => '#0668A9',
            'accent_color' => '#37CCFF',
            'background_color' => '#F8FCFF',
            'header_address' => 'Smouha',
            'brand_title_ar' => 'صيدلية الاختبار',
            'brand_title_en' => 'Test Pharmacy',
            'brand_subtitle_ar' => 'صحتك أولويتنا',
            'brand_subtitle_en' => 'Health first',
            'logo' => UploadedFile::fake()->image('store-logo.png', 512, 512),
        ])->assertSessionHasNoErrors();

        $settings = DB::table('storefront_settings')->where('store_id', $storeId)->first();
        $this->assertNotNull($settings);
        $this->assertSame('retail_pharmacy', $settings->theme_code);
        $this->assertSame('#0A8DDA', $settings->primary_color);
        $this->assertSame('Smouha', $settings->header_address);
        $branding = json_decode((string) $settings->branding, true);
        $this->assertSame('Test Pharmacy', $branding['brand_title_en']);

        $logoPath = (string) DB::table('stores')->where('id', $storeId)->value('logo_path');
        $this->assertStringStartsWith('storage/stores/'.$storeId.'/branding/', $logoPath);
        Storage::disk('public')->assertExists(substr($logoPath, strlen('storage/')));

        $this->actingAs($admin)->post(route('admin.b2c.storefront.sections.store'), [
            'store_id' => $storeId,
            'section_key' => 'best_sellers',
            'section_type' => 'best_sellers',
            'title_ar' => 'الأكثر مبيعاً',
            'title_en' => 'Best sellers',
            'sort_order' => 40,
            'config_json' => '{"limit":12}',
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $sectionId = (int) DB::table('storefront_sections')
            ->where('store_id', $storeId)
            ->where('section_key', 'best_sellers')
            ->value('id');
        $this->assertGreaterThan(0, $sectionId);

        $this->actingAs($admin)->patch(route('admin.b2c.storefront.sections.update', $sectionId), [
            'store_id' => $storeId,
            'section_key' => 'best_sellers',
            'section_type' => 'best_sellers',
            'title_ar' => 'الأكثر طلباً',
            'title_en' => 'Most ordered',
            'sort_order' => 25,
            'config_json' => '{"limit":8}',
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('storefront_sections', [
            'id' => $sectionId,
            'store_id' => $storeId,
            'title_en' => 'Most ordered',
            'sort_order' => 25,
            'is_active' => 1,
        ]);

        $this->actingAs($admin)->post(route('admin.b2c.storefront.zones.store'), [
            'store_id' => $storeId,
            'country_code' => 'eg',
            'city' => 'Alexandria',
            'area' => 'Smouha',
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $zoneId = (int) DB::table('store_service_zones')
            ->where('store_id', $storeId)
            ->where('country_code', 'EG')
            ->where('city', 'Alexandria')
            ->where('area', 'Smouha')
            ->value('id');
        $this->assertGreaterThan(0, $zoneId);

        $this->actingAs($admin)
            ->delete(route('admin.b2c.storefront.zones.destroy', $zoneId))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('store_service_zones', ['id' => $zoneId]);
    }

    public function test_storefront_admin_actions_cannot_cross_retail_tenants(): void
    {
        $storeA = $this->retailStore('BUILDER-A');
        $storeB = $this->retailStore('BUILDER-B');
        $adminA = $this->storeAdmin($storeA, 'builder-isolation@example.test');

        $this->actingAs($adminA)->put(route('admin.b2c.storefront.settings'), [
            'store_id' => $storeB,
            'theme_code' => 'retail_grocery',
            'primary_color' => '#078A43',
            'primary_dark_color' => '#006736',
            'accent_color' => '#B5F23E',
            'background_color' => '#F8FBF9',
        ])->assertNotFound();

        $this->assertDatabaseMissing('storefront_settings', ['store_id' => $storeB]);
    }

    public function test_duplicate_section_key_is_rejected_inside_same_store(): void
    {
        $storeId = $this->retailStore('BUILDER-DUP');
        $admin = $this->storeAdmin($storeId, 'builder-dup@example.test');

        DB::table('storefront_sections')->insert([
            'store_id' => $storeId,
            'section_key' => 'hero',
            'section_type' => 'hero',
            'sort_order' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->post(route('admin.b2c.storefront.sections.store'), [
            'store_id' => $storeId,
            'section_key' => 'hero',
            'section_type' => 'hero',
            'sort_order' => 20,
            'is_active' => 1,
        ])->assertSessionHasErrors('section_key');

        $this->assertSame(
            1,
            DB::table('storefront_sections')->where('store_id', $storeId)->where('section_key', 'hero')->count(),
        );
    }

    private function storeAdmin(int $storeId, string $email): User
    {
        $user = User::query()->create([
            'name' => 'Retail Admin',
            'email' => $email,
            'password' => 'Password1234',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');
        DB::table('user_store_roles')->insert([
            'user_id' => $user->id,
            'store_id' => $storeId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function retailStore(string $code): int
    {
        return (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => $code,
            'name' => $code,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

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
use Tests\TestCase;

final class WholesaleWebStorefrontBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
        Storage::fake('public');
    }

    public function test_public_web_editor_is_independent_from_customer_app_storefront(): void
    {
        $storeId = app(WholesalePrincipal::class)->storeId();
        $admin = $this->roleUser('B2B_ADMIN', 'wholesale-web-builder@example.test');

        $this->actingAs($admin)
            ->get(route('admin.b2b.module', ['module' => 'web_storefront']))
            ->assertOk()
            ->assertSee('Public Wholesale Web')
            ->assertSee('Save Web Draft')
            ->assertDontSee('Customer App home sections');

        $this->actingAs($admin)->put(route('admin.b2b.web-storefront.settings'), [
            'store_id' => $storeId,
            'public_web_enabled' => 1,
            'hero_title_ar' => 'ويب فوديكس للجملة',
            'hero_title_en' => 'FOODEX Wholesale Web',
            'hero_subtitle_ar' => 'كتالوج ويب مستقل',
            'hero_subtitle_en' => 'Independent web catalog',
            'hero_cta_ar' => 'تصفح المنتجات',
            'hero_cta_en' => 'Browse products',
            'facebook_url' => 'https://www.facebook.com/foodex.web',
            'facebook_title_ar' => 'تابع فوديكس',
            'facebook_title_en' => 'Follow FOODEX',
            'facebook_text_ar' => 'أحدث أخبار الجملة',
            'facebook_text_en' => 'Latest wholesale updates',
            'meta_title_ar' => 'فوديكس ويب',
            'meta_title_en' => 'FOODEX Web',
            'meta_description_ar' => 'كتالوج فوديكس العام',
            'meta_description_en' => 'FOODEX public catalog',
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->post(route('admin.b2b.web-storefront.sections.store'), [
            'store_id' => $storeId,
            'section_key' => 'web_brands',
            'section_type' => 'brands',
            'title_ar' => 'علامات الويب',
            'title_en' => 'Web Brands',
            'sort_order' => 30,
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->post(route('admin.b2b.web-storefront.banners.store'), [
            'store_id' => $storeId,
            'title' => 'Web Only Hero',
            'banner_image' => UploadedFile::fake()->image('web-only.webp', 1200, 420),
            'sort_order' => 1,
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('wholesale_web_settings', [
            'store_id' => $storeId,
            'is_enabled' => true,
        ]);
        $this->assertDatabaseMissing('wholesale_web_sections', [
            'store_id' => $storeId,
            'section_key' => 'web_brands',
        ]);
        $this->assertDatabaseMissing('wholesale_web_banners', [
            'store_id' => $storeId,
            'title' => 'Web Only Hero',
        ]);

        $draft = StorefrontRevision::query()
            ->where('store_id', $storeId)
            ->where('channel', 'b2b_web')
            ->where('status', 'draft')
            ->latest('id')
            ->firstOrFail();

        $this->assertTrue((bool) $draft->payload['settings']['branding']['public_web_enabled']);
        $this->assertSame('FOODEX Wholesale Web', $draft->payload['settings']['branding']['hero_title_en']);
        $this->assertSame('FOODEX Web', $draft->payload['settings']['branding']['meta_title_en']);
        $this->assertNotNull(collect($draft->payload['sections'])->firstWhere('key', 'web_brands'));
        $this->assertNotNull(collect($draft->payload['banners'])->firstWhere('title', 'Web Only Hero'));

        $this->assertDatabaseMissing('storefront_revisions', [
            'store_id' => $storeId,
            'channel' => 'b2b',
            'status' => 'draft',
        ]);
        $this->assertDatabaseMissing('storefront_sections', [
            'store_id' => $storeId,
            'section_key' => 'web_brands',
        ]);
        $this->assertDatabaseMissing('banners', [
            'store_id' => $storeId,
            'title' => 'Web Only Hero',
        ]);

        $this->actingAs($admin)->post(route('admin.b2b.web-storefront.publish'), [
            'store_id' => $storeId,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('wholesale_web_settings', [
            'store_id' => $storeId,
            'is_enabled' => true,
            'hero_title_en' => 'FOODEX Wholesale Web',
            'meta_title_en' => 'FOODEX Web',
        ]);
        $this->assertDatabaseHas('wholesale_web_sections', [
            'store_id' => $storeId,
            'section_key' => 'web_brands',
            'title_en' => 'Web Brands',
        ]);
        $this->assertDatabaseHas('wholesale_web_banners', [
            'store_id' => $storeId,
            'title' => 'Web Only Hero',
        ]);
        $this->assertDatabaseMissing('storefront_sections', [
            'store_id' => $storeId,
            'section_key' => 'web_brands',
        ]);
        $this->assertDatabaseMissing('banners', [
            'store_id' => $storeId,
            'title' => 'Web Only Hero',
        ]);

        $mobileBranding = json_decode((string) DB::table('storefront_settings')
            ->where('store_id', $storeId)
            ->value('branding'), true) ?: [];
        $this->assertArrayNotHasKey('public_web_enabled', $mobileBranding);
        $this->assertArrayNotHasKey('facebook_url', $mobileBranding);

        $this->get('/')
            ->assertOk()
            ->assertSee('FOODEX Web')
            ->assertDontSee('Add to cart');
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

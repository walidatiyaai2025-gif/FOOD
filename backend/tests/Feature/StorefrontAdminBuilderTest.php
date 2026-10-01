<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\StorefrontRevision;
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

    public function test_retail_storefront_editor_is_draft_first_until_explicit_publish(): void
    {
        $storeId = $this->retailStore('BUILDER-A');
        $admin = $this->storeAdmin($storeId, 'builder-a@example.test');

        $this->actingAs($admin)
            ->get(route('admin.b2c.module', ['module' => 'storefront', 'store_id' => $storeId]))
            ->assertOk()
            ->assertSee('Store branding &amp; theme', false)
            ->assertSee('Save Draft')
            ->assertSee('Open real app preview')
            ->assertSee('channel=b2c', false)
            ->assertSee('store_id='.$storeId, false)
            ->assertSee('mode=draft', false);

        $this->getJson('/api/v1/stores/'.$storeId.'/storefront')
            ->assertOk()
            ->assertJsonPath('theme.primary', null)
            ->assertJsonMissing(['title_en' => 'Best sellers']);

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

        $this->assertDatabaseMissing('storefront_settings', [
            'store_id' => $storeId,
            'theme_code' => 'retail_pharmacy',
        ]);
        $this->assertNull(DB::table('stores')->where('id', $storeId)->value('logo_path'));

        $draft = StorefrontRevision::query()
            ->where('store_id', $storeId)
            ->where('channel', 'b2c')
            ->where('status', 'draft')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('retail_pharmacy', $draft->payload['settings']['theme_code']);
        $this->assertSame('#0A8DDA', $draft->payload['settings']['primary_color']);
        $this->assertSame('Test Pharmacy', $draft->payload['settings']['branding']['brand_title_en']);
        $this->assertStringStartsWith('storage/stores/'.$storeId.'/branding/', (string) $draft->payload['store']['logo_path']);
        Storage::disk('public')->assertExists(substr((string) $draft->payload['store']['logo_path'], strlen('storage/')));

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

        $this->actingAs($admin)->post(route('admin.b2c.storefront.zones.store'), [
            'store_id' => $storeId,
            'country_code' => 'eg',
            'city' => 'Alexandria',
            'area' => 'Smouha',
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('storefront_sections', [
            'store_id' => $storeId,
            'section_key' => 'best_sellers',
        ]);
        $this->assertDatabaseMissing('store_service_zones', [
            'store_id' => $storeId,
            'country_code' => 'EG',
            'city' => 'Alexandria',
            'area' => 'Smouha',
        ]);

        $this->getJson('/api/v1/stores/'.$storeId.'/storefront')
            ->assertOk()
            ->assertJsonPath('theme.primary', null)
            ->assertJsonMissing(['title_en' => 'Best sellers']);

        $draft->refresh();
        $section = collect($draft->payload['sections'])->firstWhere('key', 'best_sellers');
        $zone = collect($draft->payload['service_zones'])->firstWhere('area', 'Smouha');
        $this->assertNotNull($section);
        $this->assertNotNull($zone);
        $this->assertNotEmpty($section['editor_id']);
        $this->assertNotEmpty($zone['editor_id']);

        $this->actingAs($admin)->patch(route('admin.b2c.storefront.sections.update', ['section' => $section['editor_id']]), [
            'store_id' => $storeId,
            'section_key' => 'best_sellers',
            'section_type' => 'best_sellers',
            'title_ar' => 'الأكثر طلباً',
            'title_en' => 'Most ordered',
            'sort_order' => 25,
            'config_json' => '{"limit":8}',
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->delete(route('admin.b2c.storefront.zones.destroy', ['zone' => $zone['editor_id']]), [
                'store_id' => $storeId,
            ])
            ->assertSessionHasNoErrors();

        $draft->refresh();
        $draftChecksum = $draft->checksum;

        $this->actingAs($admin)->post(route('admin.b2c.storefront.publish'), [
            'store_id' => $storeId,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('storefront_settings', [
            'store_id' => $storeId,
            'theme_code' => 'retail_pharmacy',
            'primary_color' => '#0A8DDA',
            'header_address' => 'Smouha',
        ]);
        $this->assertDatabaseHas('storefront_sections', [
            'store_id' => $storeId,
            'section_key' => 'best_sellers',
            'title_en' => 'Most ordered',
            'sort_order' => 25,
            'is_active' => 1,
        ]);
        $this->assertDatabaseMissing('store_service_zones', [
            'store_id' => $storeId,
            'area' => 'Smouha',
        ]);

        $logoPath = (string) DB::table('stores')->where('id', $storeId)->value('logo_path');
        $this->assertStringStartsWith('storage/stores/'.$storeId.'/branding/', $logoPath);
        Storage::disk('public')->assertExists(substr($logoPath, strlen('storage/')));

        $this->getJson('/api/v1/stores/'.$storeId.'/storefront')
            ->assertOk()
            ->assertJsonPath('theme.primary', '#0A8DDA')
            ->assertJsonFragment([
                'key' => 'best_sellers',
                'title_en' => 'Most ordered',
            ]);

        $draft->refresh();
        $this->assertSame('published', $draft->status);
        $this->assertSame($draftChecksum, $draft->checksum);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'storefront.revision.published',
            'user_id' => $admin->id,
            'store_id' => $storeId,
        ]);

        $publishedId = $draft->id;
        $this->actingAs($admin)->put(route('admin.b2c.storefront.settings'), [
            'store_id' => $storeId,
            'theme_code' => 'retail_grocery',
            'primary_color' => '#118844',
            'primary_dark_color' => '#0668A9',
            'accent_color' => '#37CCFF',
            'background_color' => '#F8FCFF',
        ])->assertSessionHasNoErrors();

        $nextDraft = StorefrontRevision::query()
            ->where('store_id', $storeId)
            ->where('channel', 'b2c')
            ->where('status', 'draft')
            ->latest('id')
            ->firstOrFail();

        $this->assertNotSame($publishedId, $nextDraft->id);
        $this->assertSame('#118844', $nextDraft->payload['settings']['primary_color']);
        $this->assertSame('#0A8DDA', DB::table('storefront_settings')->where('store_id', $storeId)->value('primary_color'));
    }

    public function test_retail_builder_deep_link_opens_exact_draft_context_and_labels_are_bilingual(): void
    {
        $storeId = $this->retailStore('BUILDER-DEEP-LINK');
        $admin = $this->storeAdmin($storeId, 'builder-deep-link@example.test');

        $this->actingAs($admin)
            ->get(route('admin.b2c.module', ['module' => 'storefront', 'store_id' => $storeId]))
            ->assertOk()
            ->assertSee('Save Draft')
            ->assertSee('Published')
            ->assertSee('mode=draft', false)
            ->assertSee('channel=b2c', false)
            ->assertSee('store_id='.$storeId, false);

        $this->actingAs($admin)
            ->get(route('admin.app-preview.index', [
                'app' => 'customer',
                'channel' => 'b2c',
                'store_id' => $storeId,
                'persona' => 'guest',
                'mode' => 'draft',
            ]))
            ->assertOk()
            ->assertSee('<option value="customer" selected>', false)
            ->assertSee('<option value="b2c" selected>', false)
            ->assertSee('<option value="'.$storeId.'" selected>', false)
            ->assertSee('<option value="guest" id="preview-persona-guest" selected>', false)
            ->assertSee('<option value="draft" selected>', false);

        $admin->forceFill(['locale' => 'ar'])->save();

        $this->actingAs($admin)
            ->get(route('admin.b2c.module', ['module' => 'storefront', 'store_id' => $storeId]))
            ->assertOk()
            ->assertSee('حفظ المسودة')
            ->assertSee('النسخة المنشورة')
            ->assertSee('فتح المعاينة الحقيقية');
    }

    public function test_discard_resets_retail_draft_to_current_published_without_touching_live(): void
    {
        $storeId = $this->retailStore('BUILDER-DISCARD');
        $admin = $this->storeAdmin($storeId, 'builder-discard@example.test');

        DB::table('storefront_settings')->insert([
            'store_id' => $storeId,
            'theme_code' => 'retail_grocery',
            'primary_color' => '#112233',
            'primary_dark_color' => '#001122',
            'accent_color' => '#334455',
            'background_color' => '#F8FBF9',
            'header_address' => 'Published address',
            'branding' => json_encode(['brand_title_en' => 'Published Store'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->put(route('admin.b2c.storefront.settings'), [
            'store_id' => $storeId,
            'theme_code' => 'retail_pharmacy',
            'primary_color' => '#AA5500',
            'primary_dark_color' => '#001122',
            'accent_color' => '#334455',
            'background_color' => '#F8FBF9',
            'header_address' => 'Draft address',
        ])->assertSessionHasNoErrors();

        $this->assertSame('#112233', DB::table('storefront_settings')->where('store_id', $storeId)->value('primary_color'));

        $this->actingAs($admin)->post(route('admin.b2c.storefront.discard'), [
            'store_id' => $storeId,
        ])->assertSessionHasNoErrors();

        $draft = StorefrontRevision::query()
            ->where('store_id', $storeId)
            ->where('channel', 'b2c')
            ->where('status', 'draft')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('#112233', $draft->payload['settings']['primary_color']);
        $this->assertSame('Published address', $draft->payload['settings']['header_address']);
        $this->assertSame('#112233', DB::table('storefront_settings')->where('store_id', $storeId)->value('primary_color'));
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'storefront.revision.draft_discarded',
            'user_id' => $admin->id,
            'store_id' => $storeId,
        ]);
    }

    public function test_storefront_admin_actions_cannot_cross_retail_tenants(): void
    {
        $storeA = $this->retailStore('BUILDER-ISO-A');
        $storeB = $this->retailStore('BUILDER-ISO-B');
        $adminA = $this->storeAdmin($storeA, 'builder-isolation@example.test');

        $this->actingAs($adminA)->put(route('admin.b2c.storefront.settings'), [
            'store_id' => $storeB,
            'theme_code' => 'retail_grocery',
            'primary_color' => '#078A43',
            'primary_dark_color' => '#006736',
            'accent_color' => '#B5F23E',
            'background_color' => '#F8FBF9',
        ])->assertForbidden();

        $this->assertDatabaseMissing('storefront_revisions', [
            'store_id' => $storeB,
            'channel' => 'b2c',
            'status' => 'draft',
        ]);
    }

    public function test_duplicate_section_key_is_rejected_inside_draft_without_touching_live(): void
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

    public function test_publish_requires_app_preview_publish_permission(): void
    {
        $storeId = $this->retailStore('BUILDER-PUBLISH-PERM');
        $admin = $this->storeAdmin($storeId, 'builder-publish-owner@example.test');

        $this->actingAs($admin)->put(route('admin.b2c.storefront.settings'), [
            'store_id' => $storeId,
            'theme_code' => 'retail_grocery',
            'primary_color' => '#123456',
            'primary_dark_color' => '#234567',
            'accent_color' => '#345678',
            'background_color' => '#F8FBF9',
        ])->assertSessionHasNoErrors();

        $limited = User::query()->create([
            'name' => 'Limited Storefront Manager',
            'email' => 'builder-limited@example.test',
            'password' => 'Password1234',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $role = Role::query()->create([
            'code' => 'TEST_DRAFT_ONLY',
            'name' => 'Test Draft Only',
            'scope' => 'store',
            'is_system' => false,
            'is_active' => true,
        ]);
        $role->permissions()->attach(Permission::query()->where('code', 'settings.manage')->firstOrFail());
        DB::table('user_store_roles')->insert([
            'user_id' => $limited->id,
            'store_id' => $storeId,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($limited)->post(route('admin.b2c.storefront.publish'), [
            'store_id' => $storeId,
        ])->assertForbidden();

        $this->assertDatabaseHas('storefront_revisions', [
            'store_id' => $storeId,
            'channel' => 'b2c',
            'status' => 'draft',
        ]);
        $this->assertDatabaseMissing('storefront_settings', [
            'store_id' => $storeId,
            'primary_color' => '#123456',
        ]);
    }

    public function test_super_admin_storefront_mutation_requires_explicit_support_context_and_stays_draft(): void
    {
        $storeId = $this->retailStore('BUILDER-SUPPORT');
        $admin = User::query()->create([
            'name' => 'Platform Owner',
            'email' => 'builder-owner@example.test',
            'password' => 'Password1234',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $admin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail());

        $payload = [
            'store_id' => $storeId,
            'theme_code' => 'retail_grocery',
            'primary_color' => '#078A43',
            'primary_dark_color' => '#006736',
            'accent_color' => '#B5F23E',
            'background_color' => '#F8FBF9',
        ];

        $this->actingAs($admin)
            ->put(route('admin.b2c.storefront.settings'), $payload)
            ->assertForbidden();

        $this->actingAs($admin)
            ->put(route('admin.b2c.storefront.settings'), [...$payload, 'support_access' => 1])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('storefront_settings', [
            'store_id' => $storeId,
            'theme_code' => 'retail_grocery',
        ]);
        $draft = StorefrontRevision::query()
            ->where('store_id', $storeId)
            ->where('channel', 'b2c')
            ->where('status', 'draft')
            ->firstOrFail();
        $this->assertSame('#078A43', $draft->payload['settings']['primary_color']);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'store_id' => $storeId,
            'event' => 'tenant.support_access.entered',
        ]);
    }

    public function test_retail_banner_media_contract_is_published_scoped_ordered_and_safe(): void
    {
        $storeA = $this->retailStore('BANNER-CONTRACT-A');
        $storeB = $this->retailStore('BANNER-CONTRACT-B');
        $admin = $this->storeAdmin($storeA, 'banner-contract@example.test');

        Storage::disk('public')->put('banners/'.$storeA.'/published.jpg', 'published');
        Storage::disk('public')->put('banners/'.$storeA.'/inactive.jpg', 'inactive');
        Storage::disk('public')->put('banners/'.$storeB.'/foreign.jpg', 'foreign');

        DB::table('banners')->insert([
            [
                'store_id' => $storeA,
                'title' => 'Published hero',
                'image_path' => 'storage/banners/'.$storeA.'/published.jpg',
                'target_type' => null,
                'target_id' => null,
                'target_url' => null,
                'sort_order' => 10,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'store_id' => $storeA,
                'title' => 'Missing optional media',
                'image_path' => 'storage/banners/'.$storeA.'/missing.jpg',
                'target_type' => null,
                'target_id' => null,
                'target_url' => null,
                'sort_order' => 20,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'store_id' => $storeA,
                'title' => 'Inactive banner',
                'image_path' => 'storage/banners/'.$storeA.'/inactive.jpg',
                'target_type' => null,
                'target_id' => null,
                'target_url' => null,
                'sort_order' => 1,
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'store_id' => $storeB,
                'title' => 'Foreign banner',
                'image_path' => 'storage/banners/'.$storeB.'/foreign.jpg',
                'target_type' => null,
                'target_id' => null,
                'target_url' => null,
                'sort_order' => 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $published = $this->getJson('/api/v1/stores/'.$storeA.'/banners')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Published hero')
            ->assertJsonPath('data.1.title', 'Missing optional media')
            ->assertJsonPath('data.1.image_url', null)
            ->assertJsonMissing(['title' => 'Inactive banner'])
            ->assertJsonMissing(['title' => 'Foreign banner']);

        $publishedImage = $published->json('data.0.image_url');
        $this->assertIsString($publishedImage);
        $this->assertStringContainsString('/storage/banners/'.$storeA.'/published.jpg', $publishedImage);

        $this->getJson('/api/v1/stores/'.$storeA.'/storefront')
            ->assertOk()
            ->assertJsonCount(2, 'banners')
            ->assertJsonPath('hero.title', 'Published hero')
            ->assertJsonPath('banners.1.image_url', null);

        // Missing optional media must degrade safely at read time, but Draft
        // publication still keeps the existing revision-asset safety invariant.
        // Recover the source and let the existing published revision archive it
        // before creating a Draft that may later need that historical asset.
        Storage::disk('public')->put('banners/'.$storeA.'/missing.jpg', 'recovered');
        app(\App\Services\StorefrontRevisionService::class)
            ->synchronizePublishedFromLive($admin, $storeA, 'b2c');

        $this->actingAs($admin)->post(route('admin.business.banners.store'), [
            'store_id' => $storeA,
            'title' => 'Draft hero',
            'banner_image' => UploadedFile::fake()->image('draft-hero.jpg', 800, 400),
            'sort_order' => 5,
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $this->getJson('/api/v1/stores/'.$storeA.'/banners')
            ->assertOk()
            ->assertJsonMissing(['title' => 'Draft hero']);

        $this->actingAs($admin)->post(route('admin.b2c.storefront.publish'), [
            'store_id' => $storeA,
        ])->assertSessionHasNoErrors();

        $afterPublish = $this->getJson('/api/v1/stores/'.$storeA.'/banners')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.title', 'Draft hero')
            ->assertJsonMissing(['title' => 'Foreign banner']);

        $draftImage = $afterPublish->json('data.0.image_url');
        $this->assertIsString($draftImage);
        $this->assertStringContainsString('/storage/banners/'.$storeA.'/', $draftImage);

        $this->getJson('/api/v1/stores/'.$storeA.'/storefront')
            ->assertOk()
            ->assertJsonPath('hero.title', 'Draft hero')
            ->assertJsonPath('banners.0.title', 'Draft hero');

        $this->assertDatabaseHas('banners', [
            'store_id' => $storeB,
            'title' => 'Foreign banner',
            'is_active' => true,
        ]);
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

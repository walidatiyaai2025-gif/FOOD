<?php

namespace Tests\Feature;

use App\Domain\RestaurantSaaS\RestaurantControlPlaneService;
use App\Domain\RestaurantSaaS\RestaurantMenuImportPreviewService;
use App\Domain\RestaurantSaaS\RestaurantOnboardingService;
use App\Domain\SmartOrdering\Models\SmartOrderingBranch;
use App\Domain\SmartOrdering\Models\SmartOrderingMenu;
use App\Domain\SmartOrdering\Models\SmartOrderingMenuCategory;
use App\Domain\SmartOrdering\Models\SmartOrderingMenuItem;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Domain\SmartOrdering\SmartOrderingRevisionService;
use App\Domain\SmartOrdering\SmartOrderingSiteService;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class RestaurantOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CoreReferenceSeeder::class);
    }

    public function test_launch_is_blocked_until_truthful_hard_gates_are_ready(): void
    {
        [$store, $admin] = $this->restaurant('RS-ONBOARD-A');

        app(RestaurantControlPlaneService::class)->provision($store, $admin);
        $status = app(RestaurantOnboardingService::class)->status($store, $admin);

        $this->assertFalse($status['can_launch']);
        $this->assertContains(
            'location_identity',
            collect($status['hard_blockers'])->pluck('key')->all(),
        );
        $this->assertContains(
            'hero_image',
            collect($status['hard_blockers'])->pluck('key')->all(),
        );
        $this->assertContains(
            'menu_published',
            collect($status['hard_blockers'])->pluck('key')->all(),
        );

        $this->expectException(ValidationException::class);
        app(RestaurantOnboardingService::class)->launch($store, $admin);
    }

    public function test_setup_flow_unlocks_one_stage_at_a_time(): void
    {
        [$store, $admin] = $this->restaurant('RS-ONBOARD-FLOW');
        $controlPlane = app(RestaurantControlPlaneService::class);
        $controlPlane->provision($store, $admin);

        $initial = app(RestaurantOnboardingService::class)->status($store, $admin);
        $initialSteps = collect($initial['setup_flow'])->keyBy('key');

        $this->assertSame('identity', $initial['current_step']);
        $this->assertSame('current', $initialSteps['identity']['state']);
        $this->assertSame('locked', $initialSteps['channels']['state']);
        $this->assertSame('locked', $initialSteps['operations']['state']);
        $this->assertFalse($initial['launched']);

        $controlPlane->updateLocationProfile($store, $admin, [
            'timezone' => 'Asia/Kuwait',
            'currency' => 'KWD',
            'country_code' => 'KW',
            'business_name' => 'Guided Cafe',
            'legal_name' => 'Guided Cafe LLC',
        ]);

        $site = SmartOrderingSite::query()->where('store_id', $store->getKey())->firstOrFail();
        $this->setHeroImageReady($store, $admin, $site);

        $identityReady = app(RestaurantOnboardingService::class)->status($store->fresh(), $admin);
        $identitySteps = collect($identityReady['setup_flow'])->keyBy('key');

        $this->assertSame('channels', $identityReady['current_step']);
        $this->assertSame('complete', $identitySteps['identity']['state']);
        $this->assertSame('current', $identitySteps['channels']['state']);
        $this->assertSame('locked', $identitySteps['operations']['state']);

        app(SmartOrderingSiteService::class)->replaceEntitlements($site, [
            'public_menu' => true,
            'pickup' => true,
        ], $admin);

        $channelsReady = app(RestaurantOnboardingService::class)->status($store->fresh(), $admin);
        $channelSteps = collect($channelsReady['setup_flow'])->keyBy('key');

        $this->assertSame('operations', $channelsReady['current_step']);
        $this->assertSame('complete', $channelSteps['channels']['state']);
        $this->assertSame('current', $channelSteps['operations']['state']);
        $this->assertContains(
            'opening_hours',
            collect($channelsReady['hard_blockers'])->pluck('key')->all(),
        );
    }

    public function test_ready_restaurant_launches_without_bypassing_hard_gates(): void
    {
        [$store, $admin] = $this->restaurant('RS-ONBOARD-B');
        $controlPlane = app(RestaurantControlPlaneService::class);
        $controlPlane->provision($store, $admin);
        $controlPlane->updateLocationProfile($store, $admin, [
            'timezone' => 'Asia/Kuwait',
            'currency' => 'KWD',
            'country_code' => 'KW',
            'business_name' => 'Launch Cafe',
            'legal_name' => 'Launch Cafe LLC',
            'tax_registration_number' => 'KW-100',
        ]);

        $site = SmartOrderingSite::query()->where('store_id', $store->getKey())->firstOrFail();
        $this->setHeroImageReady($store, $admin, $site);
        app(SmartOrderingSiteService::class)->replaceEntitlements($site, [
            'public_menu' => true,
            'pickup' => true,
        ], $admin);

        $branch = SmartOrderingBranch::query()->create([
            'public_id' => (string) Str::uuid(),
            'site_id' => $site->getKey(),
            'code' => 'MAIN',
            'name_ar' => 'الفرع الرئيسي',
            'name_en' => 'Main Branch',
            'timezone' => 'Asia/Kuwait',
            'is_default' => true,
            'is_active' => true,
        ]);

        DB::table('smart_ordering_branch_hours')->insert([
            'branch_id' => $branch->getKey(),
            'day_of_week' => 0,
            'opens_at' => '08:00:00',
            'closes_at' => '22:00:00',
            'is_closed' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $published = app(SmartOrderingRevisionService::class)->published($site);
        $this->assertNotNull($published);

        $menu = SmartOrderingMenu::query()->create([
            'revision_id' => $published->getKey(),
            'site_id' => $site->getKey(),
            'public_id' => (string) Str::uuid(),
            'name_ar' => 'القائمة',
            'name_en' => 'Menu',
            'is_default' => true,
            'is_visible' => true,
        ]);
        $category = SmartOrderingMenuCategory::query()->create([
            'revision_id' => $published->getKey(),
            'site_id' => $site->getKey(),
            'menu_id' => $menu->getKey(),
            'public_id' => (string) Str::uuid(),
            'name_ar' => 'الرئيسية',
            'name_en' => 'Main',
            'is_visible' => true,
        ]);
        SmartOrderingMenuItem::query()->create([
            'revision_id' => $published->getKey(),
            'site_id' => $site->getKey(),
            'category_id' => $category->getKey(),
            'public_id' => (string) Str::uuid(),
            'name_ar' => 'قهوة',
            'name_en' => 'Coffee',
            'price_override' => 1.500,
            'is_visible' => true,
        ]);

        $before = app(RestaurantOnboardingService::class)->status($store, $admin);
        $this->assertTrue($before['can_launch']);
        $this->assertSame([], $before['hard_blockers']);

        $after = app(RestaurantOnboardingService::class)->launch($store, $admin);

        $this->assertTrue($after['can_launch']);
        $this->assertDatabaseHas('restaurant_location_profiles', [
            'store_id' => $store->getKey(),
            'lifecycle_state' => 'ACTIVE',
        ]);
        $this->assertDatabaseHas('smart_ordering_sites', [
            'id' => $site->getKey(),
            'runtime_state' => 'ENABLED',
            'master_enabled' => true,
        ]);
    }

    public function test_identity_profile_uploads_the_storefront_hero_and_unlocks_branding_gate(): void
    {
        Storage::fake('public');
        [$store, $admin] = $this->restaurant('RS-ONBOARD-HERO');
        app(RestaurantControlPlaneService::class)->provision($store, $admin);
        $site = SmartOrderingSite::query()->where('store_id', $store->getKey())->firstOrFail();

        $response = $this->actingAs($admin)->put(
            route('admin.smart-ordering.onboarding.profile', $site),
            [
                'business_name' => 'Hero Cafe',
                'legal_name' => 'Hero Cafe LLC',
                'timezone' => 'Asia/Kuwait',
                'currency' => 'KWD',
                'country_code' => 'KW',
                'tax_registration_number' => 'KW-HERO',
                'hero_image' => UploadedFile::fake()->image('hero.jpg', 1600, 900),
            ],
        );

        $response->assertRedirect(
            route('admin.smart-ordering.index', ['store_id' => $store->getKey()]).'#settings-launch',
        );

        $draft = app(SmartOrderingRevisionService::class)->draft($site);
        $this->assertNotNull($draft);
        $heroPath = (string) data_get($draft->payload, 'content.identity.cover_url');
        $this->assertNotSame('', $heroPath);
        Storage::disk('public')->assertExists($heroPath);

        $status = app(RestaurantOnboardingService::class)->status($store->fresh(), $admin);
        $this->assertNotContains('hero_image', collect($status['hard_blockers'])->pluck('key')->all());
        $this->assertSame($heroPath, data_get($status, 'storefront_identity.cover_url'));
    }

    public function test_csv_import_is_staged_for_review_without_publishing_menu_data(): void
    {
        Storage::fake('local');
        [$store, $admin] = $this->restaurant('RS-ONBOARD-C');
        app(RestaurantControlPlaneService::class)->provision($store, $admin);

        $beforeItems = DB::table('smart_ordering_menu_items')->count();
        $file = UploadedFile::fake()->createWithContent(
            'menu.csv',
            "menu_name_ar,menu_name_en,item_name_ar,item_name_en,price\n"
            ."القائمة,Menu,قهوة,Coffee,1.500\n"
            ."القائمة,Menu,اختبار,Formula,=1+1\n",
        );

        $preview = app(RestaurantMenuImportPreviewService::class)->stage($store, $admin, $file);

        $this->assertSame('REVIEW_REQUIRED', $preview['status']);
        $this->assertSame(2, $preview['rows_total']);
        $this->assertSame(1, $preview['formula_rows']);
        $this->assertDatabaseHas('restaurant_onboarding_imports', [
            'store_id' => $store->getKey(),
            'actor_user_id' => $admin->getKey(),
            'status' => 'REVIEW_REQUIRED',
            'rows_total' => 2,
            'formula_rows' => 1,
        ]);
        $this->assertSame($beforeItems, DB::table('smart_ordering_menu_items')->count());
    }

    private function setHeroImageReady(Store $store, User $admin, SmartOrderingSite $site): void
    {
        $revisions = app(SmartOrderingRevisionService::class);
        $draft = $revisions->createOrReuseDraft($admin, $site);
        $payload = (array) $draft->payload;
        $content = is_array($payload['content'] ?? null) ? $payload['content'] : [];
        $identity = is_array($content['identity'] ?? null) ? $content['identity'] : [];
        $identity['cover_url'] = 'smart-ordering/branding/test/hero.jpg';
        $content['identity'] = $identity;
        $payload['content'] = $content;
        $revisions->updateDraft($admin, $draft, $payload);

        $this->assertSame(
            'smart-ordering/branding/test/hero.jpg',
            data_get(app(RestaurantOnboardingService::class)->status($store->fresh(), $admin), 'storefront_identity.cover_url'),
        );
    }

    /** @return array{Store,User} */
    private function restaurant(string $code): array
    {
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $store = Store::query()->create([
            'store_type_id' => $typeId,
            'code' => $code,
            'name' => $code,
            'is_active' => true,
        ]);

        $admin = User::query()->create([
            'name' => 'Restaurant Onboarding Admin',
            'email' => strtolower($code).'-'.uniqid().'@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $roleId = (int) Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id');
        DB::table('user_store_roles')->insert([
            'user_id' => $admin->getKey(),
            'store_id' => $store->getKey(),
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$store, $admin];
    }
}

<?php

namespace Tests\Feature;

use App\Domain\SmartOrdering\Models\SmartOrderingRevision;
use App\Domain\SmartOrdering\Models\SmartOrderingSite;
use App\Domain\SmartOrdering\Themes\Contracts\StorefrontSnapshotProvider;
use App\Domain\SmartOrdering\Themes\RestaurantMenuContentSnapshotService;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SmartOrderingStorefrontTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_storefront_renders_all_supported_templates_in_both_languages_and_modes(): void
    {
        foreach (['quick-bite', 'elegant-dining', 'cafe-bakery', 'visual-market', 'signature-brand', 'fine-dining-elegant-light', 'fine-dining-dark-luxury', 'contemporary-bistro', 'premium-arabic-dining', 'modern-minimal', 'chef-tasting', 'fresh-market-commerce'] as $theme) {
            foreach (['en', 'ar'] as $locale) {
                $snapshot = $this->snapshot($theme, $locale === 'ar' ? 'dark' : 'light');
                $this->app->instance(StorefrontSnapshotProvider::class, $this->provider($snapshot));

                $response = $this->get('/smart-ordering/demo?lang='.$locale);

                $response->assertOk()
                    ->assertSee('data-template="'.$theme.'"', false)
                    ->assertSee('dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'"', false)
                    ->assertSee('data-theme="'.($locale === 'ar' ? 'dark' : 'light').'"', false)
                    ->assertSee('aria-labelledby="so-item-dialog-title"', false)
                    ->assertSee('aria-describedby="so-item-dialog-description"', false)
                    ->assertSee('id="so-item-dialog-title"', false)
                    ->assertSee('id="so-item-dialog-description"', false)
                    ->assertDontSee('secret-token')
                    ->assertDontSee('internal_id');
            }
        }
    }

    public function test_fresh_market_template_matches_grocery_commerce_structure_in_arabic(): void
    {
        $snapshot = $this->snapshot('fresh-market-commerce', 'light');
        $this->app->instance(StorefrontSnapshotProvider::class, $this->provider($snapshot));

        $this->get('/smart-ordering/demo?lang=ar')
            ->assertOk()
            ->assertSee('data-fresh-market-template', false)
            ->assertSee('جميع المنتجات')
            ->assertSee('المنتجات المميزة')
            ->assertSee('data-theme="fresh-market-commerce"', false)
            ->assertSee('data-menu-search', false)
            ->assertSee('data-open-item', false)
            ->assertSee('fm-product-grid', false)
            ->assertSee('fm-header-menu', false);
    }

    public function test_public_item_dialog_and_icon_only_actions_keep_accessible_item_context(): void
    {
        foreach (['quick-bite', 'signature-brand'] as $theme) {
            foreach (['en', 'ar'] as $locale) {
                $snapshot = $this->snapshot($theme, 'light');
                $this->app->instance(StorefrontSnapshotProvider::class, $this->provider($snapshot));

                $label = $locale === 'ar' ? 'تخصيص برجر كلاسيك' : 'Customize Classic Burger';

                $this->get('/smart-ordering/demo?lang='.$locale)
                    ->assertOk()
                    ->assertSee('aria-label="'.$label.'"', false);
            }
        }

        $script = file_get_contents(public_path('js/smart-ordering-storefront.js'));

        $this->assertIsString($script);
        $this->assertStringContainsString('let dialogTrigger=null;', $script);
        $this->assertStringContainsString('dialogTrigger=trigger;', $script);
        $this->assertStringContainsString("dialog.querySelector('.so-dialog-close')?.focus();", $script);
        $this->assertStringContainsString("dialog.addEventListener('close'", $script);
        $this->assertStringContainsString('trigger.focus();', $script);
        $this->assertStringContainsString("dialog.removeAttribute('aria-describedby')", $script);
    }

    public function test_public_storefront_ignores_untrusted_theme_override_and_emits_seo(): void
    {
        $this->app->instance(StorefrontSnapshotProvider::class, $this->provider($this->snapshot('elegant-dining', 'light')));

        $response = $this->get('/smart-ordering/demo?theme=visual-market&appearance=dark');

        $response->assertOk()
            ->assertSee('data-template="elegant-dining"', false)
            ->assertSee('data-theme="light"', false)
            ->assertSee('<link rel="canonical" href="https://demo.foodex.test/menu">', false)
            ->assertHeader('Cache-Control');
    }

    public function test_closed_and_sold_out_states_are_non_orderable(): void
    {
        $snapshot = $this->snapshot('quick-bite', 'light');
        $snapshot['is_open'] = false;
        $snapshot['menu']['categories'][0]['items'][0]['sold_out'] = true;

        $this->app->instance(StorefrontSnapshotProvider::class, $this->provider($snapshot));

        $this->get('/smart-ordering/demo')
            ->assertOk()
            ->assertSee('We are closed for cleaning')
            ->assertSee('Sold out')
            ->assertSee('disabled', false);
    }

    public function test_unknown_storefront_is_not_enumerable(): void
    {
        $this->app->instance(StorefrontSnapshotProvider::class, $this->provider(null));
        $this->get('/smart-ordering/missing')->assertNotFound();
    }

    public function test_authorized_preview_can_switch_theme_without_changing_snapshot_and_is_not_cacheable(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $typeId = (int) DB::table('store_types')->where('code', 'B2C')->value('id');
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => $typeId,
            'code' => 'STOREFRONT-PREVIEW',
            'name' => 'Storefront Preview',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->enableRestaurantSaasForTestStore($storeId);

        DB::table('smart_ordering_sites')->insert([
            'public_id' => (string) Str::uuid(),
            'store_id' => $storeId,
            'slug' => 'demo',
            'runtime_state' => 'ENABLED',
            'master_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = User::query()->create([
            'name' => 'Storefront Preview Admin',
            'email' => 'storefront-preview@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        DB::table('user_store_roles')->insert([
            'user_id' => $admin->id,
            'store_id' => $storeId,
            'role_id' => Role::query()->where('code', 'B2C_STORE_ADMIN')->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin);
        $this->app->instance(StorefrontSnapshotProvider::class, $this->provider($this->snapshot('quick-bite', 'light')));

        $response = $this->get('/admin/smart-ordering/appearance/preview?site=demo&theme=signature-brand&appearance=dark&lang=ar&revision=draft');

        $response->assertOk()
            ->assertSee('data-template="signature-brand"', false)
            ->assertSee('data-theme="dark"', false)
            ->assertSee('dir="rtl"', false)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertSee('برجر كلاسيك')
            ->assertDontSee('secret-token');
    }

    public function test_public_asset_budget_and_responsive_contract(): void
    {
        $css = public_path('css/smart-ordering-storefront.css');
        $templateCss = public_path('css/restaurant-menu-templates-v2.css');
        $js = public_path('js/smart-ordering-storefront.js');

        $this->assertFileExists($css);
        $this->assertFileExists($templateCss);
        $this->assertFileExists($js);
        $this->assertLessThanOrEqual((int) config('smart_ordering_storefront.performance_budget.public_css_bytes'), filesize($css));
        $this->assertLessThanOrEqual((int) config('smart_ordering_storefront.performance_budget.public_js_bytes'), filesize($js));

        $stylesheet = file_get_contents($css);
        $templateStylesheet = file_get_contents($templateCss);
        $this->assertIsString($stylesheet);
        $this->assertIsString($templateStylesheet);
        $this->assertStringContainsString('@media(max-width:720px)', $stylesheet);
        $this->assertStringContainsString('@media(min-width:760px)', $stylesheet);
        $this->assertStringContainsString('[data-theme=dark]', $stylesheet);
        $this->assertStringContainsString('html[data-template="fine-dining-dark-luxury"]', $templateStylesheet);
        $this->assertStringContainsString('html[data-template="premium-arabic-dining"]', $templateStylesheet);
        $this->assertStringContainsString('html[data-template="fresh-market-commerce"]', $templateStylesheet);
        $this->assertStringContainsString('.fm-product-grid', $templateStylesheet);
        $this->assertStringContainsString('@media(max-width:620px)', $templateStylesheet);
        $this->assertStringContainsString('@media(max-width:460px)', $templateStylesheet);
        $this->assertStringContainsString('[dir="rtl"] .rm-template', $templateStylesheet);
        $this->assertStringContainsString(':focus-visible', $templateStylesheet);
    }

    public function test_public_search_exposes_localized_accessible_feedback_across_templates(): void
    {
        foreach (['quick-bite', 'elegant-dining', 'cafe-bakery', 'visual-market', 'signature-brand', 'fine-dining-elegant-light', 'fine-dining-dark-luxury', 'contemporary-bistro', 'premium-arabic-dining', 'modern-minimal', 'chef-tasting', 'fresh-market-commerce'] as $theme) {
            foreach (['en', 'ar'] as $locale) {
                $this->app->instance(StorefrontSnapshotProvider::class, $this->provider($this->snapshot($theme, 'light')));

                $response = $this->get('/smart-ordering/demo?lang='.$locale);

                $response->assertOk()
                    ->assertSee('data-menu-search-no-results="'.($locale === 'ar' ? 'لم يتم العثور على عناصر في القائمة.' : 'No menu items found.').'"', false)
                    ->assertSee('data-menu-search-clear="'.($locale === 'ar' ? 'مسح البحث' : 'Clear search').'"', false);
            }
        }

        $script = file_get_contents(public_path('js/smart-ordering-storefront.js'));
        $stylesheet = file_get_contents(public_path('css/smart-ordering-storefront.css'));

        $this->assertIsString($script);
        $this->assertIsString($stylesheet);
        $this->assertStringContainsString("announcement.setAttribute('aria-live','polite');", $script);
        $this->assertStringContainsString("feedback.classList.toggle('is-empty',count===0);", $script);
        $this->assertStringContainsString('search.focus({preventScroll:true});', $script);
        $this->assertStringContainsString('result_count:count', $script);
        $this->assertStringContainsString("$('[data-menu-search]').forEach", $script);
        $this->assertStringContainsString("const items=$('[data-menu-item]')", $script);
        $this->assertStringContainsString("$('[data-category]').forEach", $script);
        $this->assertStringContainsString("$('[data-menu-search]').forEach", $script);
        $this->assertStringContainsString("const items=$('[data-menu-item]')", $script);
        $this->assertStringContainsString("$('[data-category]').forEach", $script);
        $this->assertStringContainsString('.so-search-feedback', $stylesheet);
        $this->assertStringContainsString('.so-search-clear{min-height:44px', $stylesheet);
    }

    public function test_store_logo_storage_path_is_normalized_through_real_snapshot_and_public_render(): void
    {
        $this->seed(CoreReferenceSeeder::class);
        $storeId = (int) DB::table('stores')->insertGetId([
            'store_type_id' => DB::table('store_types')->where('code', 'B2C')->value('id'),
            'code' => 'LOGO-REGRESSION',
            'name' => 'Logo Regression Restaurant',
            'logo_path' => 'storage/stores/17/branding/logo-example.webp',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $site = SmartOrderingSite::query()->create([
            'public_id' => (string) Str::uuid(),
            'store_id' => $storeId,
            'slug' => 'logo-regression',
            'runtime_state' => 'ENABLED',
            'master_enabled' => true,
        ]);
        $revision = SmartOrderingRevision::query()->create([
            'public_id' => (string) Str::uuid(),
            'site_id' => $site->id,
            'status' => 'published',
            'schema_version' => 1,
            'payload' => ['appearance' => $this->snapshot('quick-bite', 'light')['appearance']],
            'checksum' => hash('sha256', 'logo-regression'),
            'published_at' => now(),
        ]);

        $snapshot = app(RestaurantMenuContentSnapshotService::class)->snapshot($site, $revision);

        $this->assertSame(
            '/smart-ordering/media/stores/17/branding/logo-example.webp',
            $snapshot['site']['logo_url'],
        );

        $this->app->instance(StorefrontSnapshotProvider::class, $this->provider($snapshot));
        $this->get('/smart-ordering/demo')
            ->assertOk()
            ->assertSee('src="/smart-ordering/media/stores/17/branding/logo-example.webp"', false)
            ->assertSee('data-store-logo', false)
            ->assertSee('onerror="this.hidden=true"', false);

        $media = app(\App\Services\SmartOrderingMediaService::class);
        $this->assertSame(
            '/smart-ordering/media/stores/17/branding/logo-example.webp',
            $media->publicUrl('/storage/stores/17/branding/logo-example.webp'),
        );
        $this->assertSame(
            'https://cdn.example.test/store-logo.webp',
            $media->publicUrl('https://cdn.example.test/store-logo.webp'),
        );
        $this->assertSame(
            '/smart-ordering/media/menu/items/burger.webp',
            $media->publicUrl('menu/items/burger.webp'),
        );
    }

    public function test_smart_ordering_media_is_served_from_the_current_origin_without_public_storage_symlink(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('smart-ordering/test/menu-photo.webp', 'menu-photo');

        $this->get('/smart-ordering/media/smart-ordering/test/menu-photo.webp')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->get('/smart-ordering/media/smart-ordering/test/missing.webp')
            ->assertNotFound();
    }

    public function test_public_storefront_cart_controls_use_public_item_identity_and_canonical_ordering_runtime(): void
    {
        foreach (['quick-bite', 'elegant-dining', 'cafe-bakery', 'visual-market', 'signature-brand', 'fine-dining-elegant-light', 'fine-dining-dark-luxury', 'contemporary-bistro', 'premium-arabic-dining', 'modern-minimal', 'chef-tasting', 'fresh-market-commerce'] as $theme) {
            $this->app->instance(StorefrontSnapshotProvider::class, $this->provider($this->snapshot($theme, 'light')));

            $this->get('/smart-ordering/demo')
                ->assertOk()
                ->assertSee('data-item-id="burger-1"', false)
                ->assertSee('data-cart-open', false)
                ->assertSee('data-cart-dialog', false)
                ->assertSee('data-add-to-cart', false)
                ->assertSee('data-ordering-enabled="0"', false);
        }

        $script = file_get_contents(public_path('js/smart-ordering-storefront.js'));
        $this->assertIsString($script);
        $this->assertStringContainsString('/menu-items/', $script);
        $this->assertStringContainsString('Idempotency-Key', $script);
        $this->assertStringContainsString('[data-cart-remove]', $script);
        $this->assertStringContainsString('[data-cart-qty]', $script);
    }

    private function provider(?array $snapshot): StorefrontSnapshotProvider
    {
        return new class($snapshot) implements StorefrontSnapshotProvider
        {
            public function __construct(private readonly ?array $snapshot) {}

            public function published(string $siteKey): ?array
            {
                return $siteKey === 'demo' ? $this->snapshot : null;
            }

            public function revision(string $siteKey, string $revision): ?array
            {
                return $siteKey === 'demo' ? $this->snapshot : null;
            }
        };
    }

    private function snapshot(string $theme, string $mode): array
    {
        return [
            'revision' => 'published-4',
            'locale' => 'en',
            'is_open' => true,
            'closed_message' => ['en' => 'We are closed for cleaning', 'ar' => 'مغلقون للتنظيف'],
            'secret' => 'secret-token',
            'site' => [
                'internal_id' => 88,
                'public_key' => 'demo',
                'name' => ['en' => 'Demo Restaurant', 'ar' => 'مطعم تجريبي'],
                'tagline' => ['en' => 'Fresh food, fast', 'ar' => 'طعام طازج بسرعة'],
                'description' => ['en' => 'A premium test storefront', 'ar' => 'واجهة متجر تجريبية'],
                'about' => ['en' => 'Our story', 'ar' => 'قصتنا'],
                'logo_url' => '/images/logo.png',
                'cover_url' => '/images/cover.jpg',
                'whatsapp_url' => 'https://wa.me/96500000000',
                'seo' => [
                    'title' => ['en' => 'Demo menu', 'ar' => 'قائمة تجريبية'],
                    'description' => ['en' => 'Order from Demo', 'ar' => 'اطلب من ديمو'],
                    'canonical' => 'https://demo.foodex.test/menu?tracking=private',
                ],
                'offers' => [[
                    'public_key' => 'offer-1',
                    'title' => ['en' => 'Lunch deal', 'ar' => 'عرض الغداء'],
                    'description' => ['en' => 'Combo price', 'ar' => 'سعر كومبو'],
                    'badge' => ['en' => '20% off', 'ar' => 'خصم 20%'],
                ]],
                'branches' => [[
                    'public_key' => 'branch-1',
                    'name' => ['en' => 'City', 'ar' => 'المدينة'],
                    'address' => ['en' => 'Main street', 'ar' => 'الشارع الرئيسي'],
                    'hours' => '08:00–23:00',
                ]],
            ],
            'appearance' => [
                'template' => $theme,
                'mode' => $mode,
                'customization' => [
                    'primary_color' => '#B42318',
                    'secondary_color' => '#111827',
                    'font' => 'system',
                    'card_style' => 'soft',
                    'category_style' => 'pills',
                    'button_style' => 'solid',
                ],
            ],
            'menu' => [
                'categories' => [[
                    'internal_id' => 7,
                    'public_key' => 'mains',
                    'name' => ['en' => 'Mains', 'ar' => 'الأطباق الرئيسية'],
                    'description' => ['en' => 'Made to order', 'ar' => 'تحضر حسب الطلب'],
                    'items' => [[
                        'internal_id' => 8,
                        'public_key' => 'burger-1',
                        'name' => ['en' => 'Classic Burger', 'ar' => 'برجر كلاسيك'],
                        'description' => ['en' => 'Beef, cheese, sauce', 'ar' => 'لحم وجبن وصوص'],
                        'price_display' => '2.500 KWD',
                        'image_url' => '/images/burger.jpg',
                        'best_seller' => true,
                        'tags' => ['Popular'],
                        'private_cost' => '0.800',
                        'modifiers' => [[
                            'public_key' => 'size',
                            'name' => ['en' => 'Size', 'ar' => 'الحجم'],
                            'required' => true,
                            'min' => 1,
                            'max' => 1,
                            'options' => [[
                                'public_key' => 'large',
                                'name' => ['en' => 'Large', 'ar' => 'كبير'],
                                'price_delta_display' => '+0.500 KWD',
                            ]],
                        ]],
                    ]],
                ]],
            ],
        ];
    }
}

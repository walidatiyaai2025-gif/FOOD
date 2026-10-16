<?php

namespace Tests\Feature;

use App\Domain\SmartOrdering\Themes\Contracts\StorefrontSnapshotProvider;
use Tests\TestCase;

final class SmartOrderingStorefrontTest extends TestCase
{
    public function test_public_storefront_renders_all_five_templates_in_both_languages_and_modes(): void
    {
        foreach (['quick-bite', 'elegant-dining', 'cafe-bakery', 'visual-market', 'signature-brand'] as $theme) {
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
            ->assertSee('<link rel="alternate" hreflang="en" href="https://demo.foodex.test/menu?lang=en">', false)
            ->assertSee('<link rel="alternate" hreflang="ar" href="https://demo.foodex.test/menu?lang=ar">', false)
            ->assertSee('<link rel="alternate" hreflang="x-default" href="https://demo.foodex.test/menu">', false)
            ->assertHeader('Cache-Control')
            ->assertHeader('X-Robots-Tag', 'index, follow, max-image-preview:large');
    }

    public function test_public_storefront_structured_metadata_is_safe_localized_and_query_stable(): void
    {
        foreach (['quick-bite', 'signature-brand'] as $theme) {
            foreach (['en', 'ar'] as $locale) {
                $snapshot = $this->snapshot($theme, 'light');
                $snapshot['site']['seo']['canonical'] = 'javascript:alert(1)';
                $snapshot['site']['internal_secret'] = 'site-private-secret';
                $snapshot['menu']['categories'][0]['items'][0]['private_secret'] = 'item-private-secret';
                $this->app->instance(StorefrontSnapshotProvider::class, $this->provider($snapshot));

                $response = $this->get(
                    '/smart-ordering/demo?lang='.$locale.'&utm_source=private&attribution=secret',
                );

                $response->assertOk()
                    ->assertSee('<link rel="canonical" href="http://localhost/smart-ordering/demo">', false)
                    ->assertSee(
                        '<link rel="alternate" hreflang="en" href="http://localhost/smart-ordering/demo?lang=en">',
                        false,
                    )
                    ->assertSee(
                        '<link rel="alternate" hreflang="ar" href="http://localhost/smart-ordering/demo?lang=ar">',
                        false,
                    )
                    ->assertHeader('X-Robots-Tag', 'index, follow, max-image-preview:large')
                    ->assertDontSee('site-private-secret')
                    ->assertDontSee('item-private-secret')
                    ->assertDontSee('utm_source')
                    ->assertDontSee('attribution=secret');

                $html = $response->getContent();
                $this->assertIsString($html);
                $this->assertSame(
                    1,
                    preg_match('/<script type="application\\/ld\\+json">(.*?)<\\/script>/s', $html, $matches),
                );

                $structured = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame('Restaurant', $structured['@type']);
                $this->assertSame('http://localhost/smart-ordering/demo#restaurant', $structured['@id']);
                $this->assertSame('Menu', $structured['hasMenu']['@type']);
                $this->assertSame(
                    $locale === 'ar' ? 'الأطباق الرئيسية' : 'Mains',
                    $structured['hasMenu']['hasMenuSection'][0]['name'],
                );
                $this->assertSame(
                    $locale === 'ar' ? 'برجر كلاسيك' : 'Classic Burger',
                    $structured['hasMenu']['hasMenuSection'][0]['hasMenuItem'][0]['name'],
                );
                $this->assertSame(
                    '2.500',
                    $structured['hasMenu']['hasMenuSection'][0]['hasMenuItem'][0]['offers']['price'],
                );
                $this->assertSame(
                    'KWD',
                    $structured['hasMenu']['hasMenuSection'][0]['hasMenuItem'][0]['offers']['priceCurrency'],
                );

                $encoded = json_encode($structured, JSON_THROW_ON_ERROR);
                $this->assertStringNotContainsString('internal_id', $encoded);
                $this->assertStringNotContainsString('private_cost', $encoded);
                $this->assertStringNotContainsString('secret-token', $encoded);
                $this->assertStringNotContainsString('private-secret', $encoded);
            }
        }
    }

    public function test_public_storefront_sitemap_is_sanitized_localized_and_cacheable(): void
    {
        $snapshot = $this->snapshot('quick-bite', 'light');
        $this->app->instance(StorefrontSnapshotProvider::class, $this->provider($snapshot));

        $response = $this->get('/smart-ordering/demo/sitemap.xml?utm_source=private&attribution=secret');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertHeader('Cache-Control', 'max-age=300, public, stale-while-revalidate=600')
            ->assertSee('<loc>https://demo.foodex.test/menu</loc>', false)
            ->assertSee('hreflang="en" href="https://demo.foodex.test/menu?lang=en"', false)
            ->assertSee('hreflang="ar" href="https://demo.foodex.test/menu?lang=ar"', false)
            ->assertSee('hreflang="x-default" href="https://demo.foodex.test/menu"', false)
            ->assertDontSee('tracking=private')
            ->assertDontSee('utm_source')
            ->assertDontSee('attribution=secret')
            ->assertDontSee('secret-token')
            ->assertDontSee('internal_id');

        $xml = $response->getContent();
        $this->assertIsString($xml);
        $this->assertNotFalse(simplexml_load_string($xml));
    }

    public function test_public_storefront_sitemap_uses_safe_storefront_fallback_and_unknown_is_404(): void
    {
        $snapshot = $this->snapshot('quick-bite', 'light');
        $snapshot['site']['seo']['canonical'] = 'javascript:alert(1)';
        $snapshot['site']['internal_secret'] = 'private-site-value';
        $this->app->instance(StorefrontSnapshotProvider::class, $this->provider($snapshot));

        $this->get('https://public.example.test/smart-ordering/demo/sitemap.xml?utm_source=private')
            ->assertOk()
            ->assertSee('<loc>https://public.example.test/smart-ordering/demo</loc>', false)
            ->assertSee(
                'hreflang="en" href="https://public.example.test/smart-ordering/demo?lang=en"',
                false,
            )
            ->assertDontSee('/sitemap.xml</loc>', false)
            ->assertDontSee('javascript:alert(1)')
            ->assertDontSee('private-site-value')
            ->assertDontSee('utm_source');

        $this->app->instance(StorefrontSnapshotProvider::class, $this->provider(null));
        $this->get('/smart-ordering/missing/sitemap.xml')->assertNotFound();
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
        $this->withoutMiddleware();
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
        $js = public_path('js/smart-ordering-storefront.js');

        $this->assertFileExists($css);
        $this->assertFileExists($js);
        $this->assertLessThanOrEqual((int) config('smart_ordering_storefront.performance_budget.public_css_bytes'), filesize($css));
        $this->assertLessThanOrEqual((int) config('smart_ordering_storefront.performance_budget.public_js_bytes'), filesize($js));

        $stylesheet = file_get_contents($css);
        $this->assertIsString($stylesheet);
        $this->assertStringContainsString('@media(max-width:720px)', $stylesheet);
        $this->assertStringContainsString('@media(min-width:760px)', $stylesheet);
        $this->assertStringContainsString('[data-theme=dark]', $stylesheet);
    }

    public function test_public_search_exposes_localized_accessible_feedback_across_templates(): void
    {
        foreach (['quick-bite', 'elegant-dining', 'cafe-bakery', 'visual-market', 'signature-brand'] as $theme) {
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

    public function test_public_storefront_templates_render_safe_menu_media_placeholders(): void
    {
        $placeholder = '/images/smart-ordering/menu-media-placeholder.svg';

        $this->assertFileExists(public_path('images/smart-ordering/menu-media-placeholder.svg'));

        foreach (['quick-bite', 'elegant-dining', 'cafe-bakery', 'visual-market', 'signature-brand'] as $theme) {
            foreach (['en', 'ar'] as $locale) {
                $snapshot = $this->snapshot($theme, 'light');
                $snapshot['menu']['categories'][0]['image_url'] = null;
                $snapshot['menu']['categories'][0]['items'][0]['image_url'] = 'javascript:alert(1)';
                $this->app->instance(StorefrontSnapshotProvider::class, $this->provider($snapshot));

                $this->get('/smart-ordering/demo?lang='.$locale)
                    ->assertOk()
                    ->assertSee($placeholder, false)
                    ->assertDontSee('javascript:alert(1)', false);
            }
        }
    }

    public function test_public_storefront_cart_controls_use_public_item_identity_and_canonical_ordering_runtime(): void
    {
        foreach (['quick-bite', 'elegant-dining', 'cafe-bakery', 'visual-market', 'signature-brand'] as $theme) {
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

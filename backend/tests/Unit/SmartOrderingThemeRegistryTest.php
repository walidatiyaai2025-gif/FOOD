<?php

namespace Tests\Unit;

use App\Domain\SmartOrdering\Themes\StorefrontThemeRenderer;
use App\Domain\SmartOrdering\Themes\ThemeRegistry;
use PHPUnit\Framework\TestCase;

final class SmartOrderingThemeRegistryTest extends TestCase
{
    public function test_registry_exposes_five_structurally_distinct_templates(): void
    {
        $registry = new ThemeRegistry;

        $this->assertSame([
            'quick-bite',
            'elegant-dining',
            'cafe-bakery',
            'visual-market',
            'signature-brand',
        ], $registry->ids());

        $this->assertCount(5, array_unique(array_column($registry->all(), 'view')));
        $this->assertCount(5, array_unique(array_column($registry->all(), 'layout')));
    }

    public function test_customization_is_controlled_and_css_injection_is_rejected(): void
    {
        $registry = new ThemeRegistry;

        $safe = $registry->sanitizeCustomization([
            'primary_color' => '#12abef',
            'secondary_color' => 'red; background:url(javascript:alert(1))',
            'font' => 'unknown-font',
            'button_style' => 'pill',
        ]);

        $this->assertSame('#12ABEF', $safe['primary_color']);
        $this->assertSame('#111827', $safe['secondary_color']);
        $this->assertSame('system', $safe['font']);
        $this->assertSame('pill', $safe['button_style']);
    }

    public function test_switching_theme_preserves_canonical_menu_data(): void
    {
        $renderer = new StorefrontThemeRenderer(new ThemeRegistry);
        $snapshot = $this->snapshot();

        $quick = $renderer->prepare($snapshot, 'quick-bite', 'light', 'en');
        $market = $renderer->prepare($snapshot, 'visual-market', 'dark', 'en');

        $this->assertSame($quick['menu'], $market['menu']);
        $this->assertSame($quick['site']['public_key'], $market['site']['public_key']);
        $this->assertSame('quick-bite', $quick['theme']['id']);
        $this->assertSame('visual-market', $market['theme']['id']);
        $this->assertSame('dark', $market['appearance']['mode']);
    }

    public function test_renderer_drops_private_fields_and_supports_rtl_translation(): void
    {
        $renderer = new StorefrontThemeRenderer(new ThemeRegistry);
        $prepared = $renderer->prepare($this->snapshot(), null, null, 'ar');

        $encoded = json_encode($prepared, JSON_THROW_ON_ERROR);

        $this->assertSame('rtl', $prepared['direction']);
        $this->assertSame('مطعم تجريبي', $prepared['site']['name']);
        $this->assertStringNotContainsString('internal_id', $encoded);
        $this->assertStringNotContainsString('secret-token', $encoded);
    }

    private function snapshot(): array
    {
        return [
            'revision' => 'rev-7',
            'locale' => 'en',
            'is_open' => true,
            'secret' => 'secret-token',
            'site' => [
                'internal_id' => 99,
                'public_key' => 'demo',
                'name' => ['en' => 'Demo Restaurant', 'ar' => 'مطعم تجريبي'],
                'seo' => ['canonical' => 'https://demo.foodex.test/?private=1'],
            ],
            'appearance' => [
                'template' => 'quick-bite',
                'mode' => 'light',
                'customization' => ['primary_color' => '#B42318'],
            ],
            'menu' => [
                'categories' => [[
                    'internal_id' => 5,
                    'public_key' => 'burgers',
                    'name' => ['en' => 'Burgers', 'ar' => 'برجر'],
                    'items' => [[
                        'internal_id' => 10,
                        'public_key' => 'classic',
                        'name' => ['en' => 'Classic', 'ar' => 'كلاسيك'],
                        'description' => ['en' => 'Beef burger', 'ar' => 'برجر لحم'],
                        'price_display' => '2.500 KWD',
                        'private_cost' => '0.600',
                    ]],
                ]],
            ],
        ];
    }
}

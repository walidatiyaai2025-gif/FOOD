<?php

namespace App\Domain\SmartOrdering\Themes;

use InvalidArgumentException;

final class ThemeRegistry
{
    private const TEMPLATES = [
        'quick-bite' => ['label' => 'Quick Bite', 'view' => 'public.smart-ordering.templates.quick-bite', 'layout' => 'fast-ordering'],
        'elegant-dining' => ['label' => 'Elegant Dining', 'view' => 'public.smart-ordering.templates.elegant-dining', 'layout' => 'editorial'],
        'cafe-bakery' => ['label' => 'Café & Bakery', 'view' => 'public.smart-ordering.templates.cafe-bakery', 'layout' => 'modifier-forward'],
        'visual-market' => ['label' => 'Visual Market', 'view' => 'public.smart-ordering.templates.visual-market', 'layout' => 'dense-catalog'],
        'signature-brand' => ['label' => 'Signature Brand', 'view' => 'public.smart-ordering.templates.signature-brand', 'layout' => 'mini-site'],
    ];

    private const FONTS = ['system', 'modern', 'rounded', 'editorial'];
    private const CARD_STYLES = ['soft', 'outlined', 'elevated', 'minimal'];
    private const CATEGORY_STYLES = ['pills', 'tabs', 'rail', 'cards'];
    private const BUTTON_STYLES = ['solid', 'soft', 'outline', 'pill'];

    public function all(): array { return self::TEMPLATES; }
    public function ids(): array { return array_keys(self::TEMPLATES); }

    public function definition(string $theme): array
    {
        $theme = $this->resolve($theme);
        return ['id' => $theme, ...self::TEMPLATES[$theme]];
    }

    public function resolve(?string $theme): string
    {
        $theme = strtolower(trim((string) $theme));
        return array_key_exists($theme, self::TEMPLATES) ? $theme : 'quick-bite';
    }

    public function require(string $theme): string
    {
        if (! array_key_exists($theme, self::TEMPLATES)) {
            throw new InvalidArgumentException('Unsupported Smart Ordering storefront theme.');
        }
        return $theme;
    }

    public function sanitizeAppearance(?string $appearance): string
    {
        return strtolower((string) $appearance) === 'dark' ? 'dark' : 'light';
    }

    public function sanitizeLocale(?string $locale): string
    {
        return strtolower((string) $locale) === 'ar' ? 'ar' : 'en';
    }

    public function sanitizeCustomization(array $customization): array
    {
        return [
            'primary_color' => $this->sanitizeColor($customization['primary_color'] ?? null, '#B42318'),
            'secondary_color' => $this->sanitizeColor($customization['secondary_color'] ?? null, '#111827'),
            'font' => $this->enum($customization['font'] ?? null, self::FONTS, 'system'),
            'card_style' => $this->enum($customization['card_style'] ?? null, self::CARD_STYLES, 'soft'),
            'category_style' => $this->enum($customization['category_style'] ?? null, self::CATEGORY_STYLES, 'pills'),
            'button_style' => $this->enum($customization['button_style'] ?? null, self::BUTTON_STYLES, 'solid'),
            'show_offers' => (bool) ($customization['show_offers'] ?? true),
            'show_best_sellers' => (bool) ($customization['show_best_sellers'] ?? true),
            'show_about' => (bool) ($customization['show_about'] ?? true),
            'show_branches' => (bool) ($customization['show_branches'] ?? true),
        ];
    }

    public function cssVariables(array $customization): array
    {
        $safe = $this->sanitizeCustomization($customization);
        return ['--so-primary' => $safe['primary_color'], '--so-secondary' => $safe['secondary_color']];
    }

    private function sanitizeColor(mixed $value, string $fallback): string
    {
        $value = trim((string) $value);
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1 ? strtoupper($value) : $fallback;
    }

    private function enum(mixed $value, array $allowed, string $fallback): string
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, $allowed, true) ? $value : $fallback;
    }
}

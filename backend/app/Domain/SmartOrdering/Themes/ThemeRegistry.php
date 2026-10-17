<?php

namespace App\Domain\SmartOrdering\Themes;

use InvalidArgumentException;

final class ThemeRegistry
{
    public const SCHEMA_VERSION = 2;

    private const PRODUCTION_GENERATION = 'restaurant-menu-v2';

    private const PRODUCTION_DEFAULT = 'modern-minimal';

    private const LEGACY_DEFAULT = 'quick-bite';

    private const TEMPLATES = [
        'fine-dining-elegant-light' => [
            'label' => 'Fine Dining — Elegant Light',
            'view' => 'public.smart-ordering.templates.fine-dining-elegant-light',
            'layout' => 'editorial-list',
            'version' => 1,
            'generation' => self::PRODUCTION_GENERATION,
            'dark' => false,
            'supports' => ['hero', 'categories', 'featured', 'chef_note', 'beverages', 'desserts', 'hours', 'contact', 'reservation_cta'],
        ],
        'fine-dining-dark-luxury' => [
            'label' => 'Fine Dining — Dark Luxury',
            'view' => 'public.smart-ordering.templates.fine-dining-dark-luxury',
            'layout' => 'featured-editorial',
            'version' => 1,
            'generation' => self::PRODUCTION_GENERATION,
            'dark' => true,
            'supports' => ['hero', 'categories', 'featured', 'chef_note', 'tasting', 'beverages', 'desserts', 'hours', 'contact', 'reservation_cta'],
        ],
        'contemporary-bistro' => [
            'label' => 'Contemporary Bistro',
            'view' => 'public.smart-ordering.templates.contemporary-bistro',
            'layout' => 'thumbnail-grid',
            'version' => 1,
            'generation' => self::PRODUCTION_GENERATION,
            'dark' => false,
            'supports' => ['hero', 'categories', 'featured', 'beverages', 'desserts', 'hours', 'contact', 'reservation_cta'],
        ],
        'premium-arabic-dining' => [
            'label' => 'Premium Arabic Dining',
            'view' => 'public.smart-ordering.templates.premium-arabic-dining',
            'layout' => 'rtl-first-editorial',
            'version' => 1,
            'generation' => self::PRODUCTION_GENERATION,
            'dark' => false,
            'supports' => ['hero', 'categories', 'featured', 'chef_note', 'tasting', 'beverages', 'desserts', 'hours', 'contact', 'reservation_cta'],
        ],
        'modern-minimal' => [
            'label' => 'Modern Minimal Restaurant',
            'view' => 'public.smart-ordering.templates.modern-minimal',
            'layout' => 'minimal-list',
            'version' => 1,
            'generation' => self::PRODUCTION_GENERATION,
            'dark' => false,
            'supports' => ['hero', 'categories', 'featured', 'beverages', 'desserts', 'hours', 'contact', 'reservation_cta'],
        ],
        'chef-tasting' => [
            'label' => 'Chef / Tasting Menu',
            'view' => 'public.smart-ordering.templates.chef-tasting',
            'layout' => 'course-timeline',
            'version' => 1,
            'generation' => self::PRODUCTION_GENERATION,
            'dark' => false,
            'supports' => ['hero', 'categories', 'featured', 'chef_note', 'tasting', 'beverages', 'desserts', 'hours', 'contact', 'reservation_cta'],
        ],

        // Legacy storefront IDs remain supported so existing restaurants never lose their selected presentation.
        'quick-bite' => ['label' => 'Quick Bite', 'view' => 'public.smart-ordering.templates.quick-bite', 'layout' => 'fast-ordering', 'version' => 1, 'generation' => 'legacy', 'dark' => false, 'supports' => ['hero', 'categories']],
        'elegant-dining' => ['label' => 'Elegant Dining', 'view' => 'public.smart-ordering.templates.elegant-dining', 'layout' => 'editorial', 'version' => 1, 'generation' => 'legacy', 'dark' => false, 'supports' => ['hero', 'categories']],
        'cafe-bakery' => ['label' => 'Café & Bakery', 'view' => 'public.smart-ordering.templates.cafe-bakery', 'layout' => 'modifier-forward', 'version' => 1, 'generation' => 'legacy', 'dark' => false, 'supports' => ['hero', 'categories']],
        'visual-market' => ['label' => 'Visual Market', 'view' => 'public.smart-ordering.templates.visual-market', 'layout' => 'dense-catalog', 'version' => 1, 'generation' => 'legacy', 'dark' => false, 'supports' => ['hero', 'categories']],
        'signature-brand' => ['label' => 'Signature Brand', 'view' => 'public.smart-ordering.templates.signature-brand', 'layout' => 'mini-site', 'version' => 1, 'generation' => 'legacy', 'dark' => false, 'supports' => ['hero', 'categories', 'featured', 'hours', 'contact']],
    ];

    private const FONTS = ['system', 'modern', 'rounded', 'editorial'];

    private const CARD_STYLES = ['soft', 'outlined', 'elevated', 'minimal'];

    private const CATEGORY_STYLES = ['pills', 'tabs', 'rail', 'cards'];

    private const BUTTON_STYLES = ['solid', 'soft', 'outline', 'pill'];

    public function all(): array
    {
        return self::TEMPLATES;
    }

    public function ids(): array
    {
        return array_keys(self::TEMPLATES);
    }

    public function production(): array
    {
        return array_filter(
            self::TEMPLATES,
            static fn (array $definition): bool => $definition['generation'] === self::PRODUCTION_GENERATION,
        );
    }

    public function productionIds(): array
    {
        return array_keys($this->production());
    }

    public function productionDefault(): string
    {
        return self::PRODUCTION_DEFAULT;
    }

    public function definition(string $theme): array
    {
        $theme = $this->resolve($theme);

        return ['id' => $theme, ...self::TEMPLATES[$theme]];
    }

    public function productionDefinition(?string $theme, mixed $version = null): array
    {
        $requested = strtolower(trim((string) $theme));
        $resolved = $this->resolveProduction($theme, $version);
        $definition = ['id' => $resolved, ...self::TEMPLATES[$resolved]];

        return [
            ...$definition,
            'schema_version' => self::SCHEMA_VERSION,
            'requested_id' => $requested === '' ? null : $requested,
            'requested_version' => $this->normalizeVersion($version),
            'fallback' => $requested !== '' && $resolved !== $requested,
        ];
    }

    public function resolve(?string $theme): string
    {
        $theme = strtolower(trim((string) $theme));

        return array_key_exists($theme, self::TEMPLATES) ? $theme : self::LEGACY_DEFAULT;
    }

    public function resolveProduction(?string $theme, mixed $version = null): string
    {
        $theme = strtolower(trim((string) $theme));
        $definition = self::TEMPLATES[$theme] ?? null;

        if (! is_array($definition) || $definition['generation'] !== self::PRODUCTION_GENERATION) {
            return self::PRODUCTION_DEFAULT;
        }

        $requestedVersion = $this->normalizeVersion($version);
        if ($requestedVersion !== null && $requestedVersion !== (int) $definition['version']) {
            return self::PRODUCTION_DEFAULT;
        }

        return $theme;
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

    private function normalizeVersion(mixed $version): ?int
    {
        if ($version === null || $version === '') {
            return null;
        }

        if (! is_numeric($version)) {
            return -1;
        }

        return (int) $version;
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

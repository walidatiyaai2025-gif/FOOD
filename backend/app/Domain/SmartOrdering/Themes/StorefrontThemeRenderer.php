<?php

namespace App\Domain\SmartOrdering\Themes;

final class StorefrontThemeRenderer
{
    public function __construct(private readonly ThemeRegistry $themes) {}

    public function prepare(array $snapshot, ?string $themeOverride = null, ?string $appearanceOverride = null, ?string $localeOverride = null, ?string $canonicalFallback = null): array
    {
        $site = is_array($snapshot['site'] ?? null) ? $snapshot['site'] : [];
        $menu = is_array($snapshot['menu'] ?? null) ? $snapshot['menu'] : [];
        $appearance = is_array($snapshot['appearance'] ?? null) ? $snapshot['appearance'] : [];

        $locale = $this->themes->sanitizeLocale($localeOverride ?? ($snapshot['locale'] ?? 'en'));
        $theme = $this->themes->resolve($themeOverride ?? ($appearance['template'] ?? 'quick-bite'));
        $mode = $this->themes->sanitizeAppearance($appearanceOverride ?? ($appearance['mode'] ?? 'light'));
        $customization = $this->themes->sanitizeCustomization((array) ($appearance['customization'] ?? []));

        return [
            'theme' => $this->themes->definition($theme),
            'appearance' => ['mode' => $mode, 'customization' => $customization, 'css_variables' => $this->themes->cssVariables($customization)],
            'locale' => $locale,
            'direction' => $locale === 'ar' ? 'rtl' : 'ltr',
            'revision' => $this->plain($snapshot['revision'] ?? 'published', 64),
            'site' => $this->site($site, $locale, $canonicalFallback),
            'menu' => $this->menu($menu, $locale),
            'is_open' => (bool) ($snapshot['is_open'] ?? true),
            'closed_message' => $this->translated($snapshot['closed_message'] ?? [], $locale, $locale === 'ar' ? 'المطعم مغلق الآن' : 'We are currently closed'),
        ];
    }

    public function view(array $prepared): string
    {
        return 'public.smart-ordering.storefront';
    }

    private function site(array $site, string $locale, ?string $canonicalFallback): array
    {
        $seo = is_array($site['seo'] ?? null) ? $site['seo'] : [];

        return [
            'public_key' => $this->plain($site['public_key'] ?? '', 100),
            'name' => $this->translated($site['name'] ?? [], $locale, 'Restaurant'),
            'tagline' => $this->translated($site['tagline'] ?? [], $locale, ''),
            'description' => $this->translated($site['description'] ?? [], $locale, ''),
            'logo_url' => $this->safeUrl($site['logo_url'] ?? null),
            'cover_url' => $this->safeUrl($site['cover_url'] ?? null),
            'phone' => $this->plain($site['phone'] ?? '', 40),
            'whatsapp_url' => $this->safeUrl($site['whatsapp_url'] ?? null),
            'about' => $this->translated($site['about'] ?? [], $locale, ''),
            'branches' => $this->branches((array) ($site['branches'] ?? []), $locale),
            'offers' => $this->offers((array) ($site['offers'] ?? []), $locale),
            'seo' => [
                'title' => $this->translated($seo['title'] ?? [], $locale, $this->translated($site['name'] ?? [], $locale, 'Restaurant')),
                'description' => $this->translated($seo['description'] ?? [], $locale, $this->translated($site['description'] ?? [], $locale, '')),
                'canonical' => $this->safeCanonical($seo['canonical'] ?? $canonicalFallback),
                'image_url' => $this->safeUrl($seo['image_url'] ?? ($site['cover_url'] ?? null)),
            ],
        ];
    }

    private function menu(array $menu, string $locale): array
    {
        $categories = [];
        foreach ((array) ($menu['categories'] ?? []) as $category) {
            if (! is_array($category) || (($category['visible'] ?? true) === false)) {
                continue;
            }

            $items = [];
            foreach ((array) ($category['items'] ?? []) as $item) {
                if (! is_array($item) || (($item['visible'] ?? true) === false)) {
                    continue;
                }

                $items[] = [
                    'public_key' => $this->plain($item['public_key'] ?? '', 100),
                    'name' => $this->translated($item['name'] ?? [], $locale, 'Item'),
                    'description' => $this->translated($item['description'] ?? [], $locale, ''),
                    'image_url' => $this->safeUrl($item['image_url'] ?? null),
                    'price_display' => $this->plain($item['price_display'] ?? '', 50),
                    'sold_out' => (bool) ($item['sold_out'] ?? false),
                    'featured' => (bool) ($item['featured'] ?? false),
                    'best_seller' => (bool) ($item['best_seller'] ?? false),
                    'new' => (bool) ($item['new'] ?? false),
                    'tags' => array_slice(array_values(array_filter(array_map(fn ($tag) => $this->plain($tag, 30), (array) ($item['tags'] ?? [])))), 0, 8),
                    'modifiers' => $this->modifiers((array) ($item['modifiers'] ?? []), $locale),
                ];
            }

            $categories[] = [
                'public_key' => $this->plain($category['public_key'] ?? '', 100),
                'name' => $this->translated($category['name'] ?? [], $locale, 'Menu'),
                'description' => $this->translated($category['description'] ?? [], $locale, ''),
                'image_url' => $this->safeUrl($category['image_url'] ?? null),
                'items' => $items,
            ];
        }

        return ['categories' => $categories];
    }

    private function modifiers(array $groups, string $locale): array
    {
        $safe = [];
        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $options = [];
            foreach ((array) ($group['options'] ?? []) as $option) {
                if (! is_array($option)) {
                    continue;
                }

                $options[] = [
                    'public_key' => $this->plain($option['public_key'] ?? '', 100),
                    'name' => $this->translated($option['name'] ?? [], $locale, ''),
                    'price_delta_display' => $this->plain($option['price_delta_display'] ?? '', 40),
                    'available' => (bool) ($option['available'] ?? true),
                ];
            }

            $safe[] = [
                'public_key' => $this->plain($group['public_key'] ?? '', 100),
                'name' => $this->translated($group['name'] ?? [], $locale, ''),
                'required' => (bool) ($group['required'] ?? false),
                'min' => max(0, (int) ($group['min'] ?? 0)),
                'max' => max(1, (int) ($group['max'] ?? 1)),
                'options' => $options,
            ];
        }

        return $safe;
    }

    private function branches(array $branches, string $locale): array
    {
        $safe = [];
        foreach ($branches as $branch) {
            if (! is_array($branch)) {
                continue;
            }

            $safe[] = [
                'public_key' => $this->plain($branch['public_key'] ?? '', 100),
                'name' => $this->translated($branch['name'] ?? [], $locale, ''),
                'address' => $this->translated($branch['address'] ?? [], $locale, ''),
                'hours' => $this->plain($branch['hours'] ?? '', 120),
                'map_url' => $this->safeUrl($branch['map_url'] ?? null),
            ];
        }

        return $safe;
    }

    private function offers(array $offers, string $locale): array
    {
        $safe = [];
        foreach ($offers as $offer) {
            if (! is_array($offer) || (($offer['visible'] ?? true) === false)) {
                continue;
            }

            $safe[] = [
                'public_key' => $this->plain($offer['public_key'] ?? '', 100),
                'title' => $this->translated($offer['title'] ?? [], $locale, ''),
                'description' => $this->translated($offer['description'] ?? [], $locale, ''),
                'badge' => $this->translated($offer['badge'] ?? [], $locale, ''),
                'image_url' => $this->safeUrl($offer['image_url'] ?? null),
            ];
        }

        return $safe;
    }

    private function translated(mixed $value, string $locale, string $fallback): string
    {
        if (is_string($value) || is_numeric($value)) {
            return $this->plain($value, 1000);
        }

        if (! is_array($value)) {
            return $fallback;
        }

        return $this->plain($value[$locale] ?? $value[$locale === 'ar' ? 'en' : 'ar'] ?? $fallback, 1000);
    }

    private function plain(mixed $value, int $max): string
    {
        return mb_substr(trim(strip_tags((string) $value)), 0, $max);
    }

    private function safeUrl(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, '/')) {
            return $value;
        }

        $parts = parse_url($value);
        if (! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return null;
        }

        return $value;
    }

    private function safeCanonical(mixed $value): ?string
    {
        $url = $this->safeUrl($value);
        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return $url;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$parts['host'].$port.($parts['path'] ?? '/');
    }
}

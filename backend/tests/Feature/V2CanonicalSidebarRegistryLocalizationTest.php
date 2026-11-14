<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * V2 P384: a registered Sidebar destination must never surface a raw
 * translation key in either locale, even when the other locale has a value.
 */
final class V2CanonicalSidebarRegistryLocalizationTest extends TestCase
{
    public function test_every_current_sidebar_group_and_item_has_explicit_arabic_and_english_labels(): void
    {
        $registry = (array) config('admin_sidebar');

        foreach (['groups', 'items'] as $section) {
            $definitions = (array) ($registry[$section] ?? []);
            $this->assertNotEmpty($definitions, "The canonical Sidebar {$section} registry must not be empty.");

            foreach ($definitions as $key => $definition) {
                $this->assertIsArray($definition, "Sidebar {$section}.{$key} must have a definition.");
                $this->assertArrayHasKey('label', $definition, "Sidebar {$section}.{$key} needs a localized label key.");

                $translationKey = $definition['label'];
                $this->assertIsString($translationKey, "Sidebar {$section}.{$key} must use a translation key.");
                $this->assertMatchesRegularExpression(
                    '/^[a-z][a-z0-9_]*(?:[.][a-z0-9_]+)+$/',
                    $translationKey,
                    "Sidebar {$section}.{$key} must use an explicit nested translation key.",
                );

                foreach (['ar', 'en'] as $locale) {
                    $context = "Sidebar {$section}.{$key} ({$translationKey}) / {$locale}";

                    // The third argument disables Laravel's cross-locale fallback.
                    // A missing Arabic key must not silently render its English peer.
                    $this->assertTrue(
                        Lang::has($translationKey, $locale, false),
                        "{$context}: translation is absent in the selected locale.",
                    );

                    $label = Lang::get($translationKey, [], $locale, false);
                    $this->assertIsString($label, "{$context}: translation must resolve to text.");
                    $this->assertNotSame('', trim($label), "{$context}: translation must not be blank.");
                    $this->assertNotSame($translationKey, $label, "{$context}: raw key is not a label.");
                }
            }
        }
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * P168/P384: the existing registry-wide human-label test uses the normal
 * translation fallback. Require each selected locale to own its label.
 */
final class V2CanonicalSidebarRegistryLocalizationTest extends TestCase
{
    public function test_each_canonical_sidebar_label_exists_in_its_requested_locale_without_fallback(): void
    {
        $registry = (array) config('admin_sidebar');

        foreach (['groups', 'items'] as $section) {
            foreach ((array) ($registry[$section] ?? []) as $key => $definition) {
                $this->assertIsArray($definition, "Sidebar {$section}.{$key} must have a definition.");
                $translationKey = $definition['label'] ?? null;
                $this->assertIsString($translationKey, "Sidebar {$section}.{$key} needs a translation key.");

                foreach (['ar', 'en'] as $locale) {
                    $context = "Sidebar {$section}.{$key} ({$translationKey}) / {$locale}";

                    // Unlike __(), has/get with fallback=false cannot silently
                    // accept the other language's label for a missing key.
                    $this->assertTrue(
                        Lang::has($translationKey, $locale, false),
                        "{$context}: selected-locale translation is missing.",
                    );

                    $label = Lang::get($translationKey, [], $locale, false);
                    $this->assertIsString($label, "{$context}: expected a translated string.");
                    $this->assertNotSame('', trim($label), "{$context}: label is blank.");
                    $this->assertNotSame($translationKey, $label, "{$context}: raw translation key is visible.");
                }
            }
        }
    }
}

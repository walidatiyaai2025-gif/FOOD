<?php

namespace Tests\Feature;

use App\Services\AdminSidebarManager;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * V2 #3365 / P384: test the entire currently registered Sidebar catalog,
 * rather than relying on the seven hand-picked examples in #2904 / #2944.
 *
 * This is read-only validation of real Laravel runtime configuration. It does
 * not change saved Sidebar overrides, route authorization or tenant access.
 */
final class V2P384SidebarRegistryLabelCoverageTest extends TestCase
{
    public function test_every_current_sidebar_group_and_item_has_native_arabic_and_english_labels(): void
    {
        $labels = $this->registeredLabels();

        // The P384 inventory baseline currently contains 21 business groups
        // and 69 registered items. A deliberate registry migration must update
        // the coverage record rather than silently losing existing labels.
        $this->assertCount(90, $labels);

        foreach ($labels as $entry => $key) {
            $this->assertStringContainsString('.', $key, "{$entry}: label must be a canonical translation key.");

            foreach (['ar', 'en'] as $locale) {
                $this->assertTrue(
                    Lang::has($key, $locale, false),
                    "{$entry}: {$key} must exist in {$locale} without fallback.",
                );

                $value = __($key, [], $locale);
                $this->assertIsString($value);
                $this->assertNotSame('', trim($value), "{$entry}: {$locale} label cannot be empty.");
                $this->assertNotSame($key, $value, "{$entry}: {$locale} must not expose a raw translation key.");

                if ($locale === 'ar') {
                    // FOODEX, ERP, QR and B2B/B2C may be retained as approved
                    // acronyms, but must not replace the Arabic business label.
                    $this->assertSame(
                        1,
                        preg_match('/[\x{0600}-\x{06FF}]/u', $value),
                        "{$entry}: Arabic Sidebar text must include Arabic business wording.",
                    );
                } else {
                    $this->assertSame(
                        0,
                        preg_match('/[\x{0600}-\x{06FF}]/u', $value),
                        "{$entry}: English Sidebar text must not contain Arabic system wording.",
                    );
                }
            }
        }
    }

    public function test_switching_selected_locale_preserves_the_same_canonical_registry_and_keys(): void
    {
        $labels = $this->registeredLabels();

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            $this->assertSame($labels, $this->registeredLabels());

            foreach ($labels as $entry => $key) {
                $resolved = __($key);
                $this->assertSame(
                    __($key, [], $locale),
                    $resolved,
                    "{$entry}: selected locale must determine system-owned Sidebar text.",
                );
            }
        }
    }

    /** @return array<string, string> */
    private function registeredLabels(): array
    {
        $registry = app(AdminSidebarManager::class)->registry();
        $labels = [];

        foreach (['groups', 'items'] as $section) {
            $this->assertIsArray($registry[$section] ?? null);

            foreach ($registry[$section] as $itemKey => $definition) {
                $this->assertIsArray($definition);
                $this->assertIsString($itemKey);

                $label = $definition['label'] ?? null;
                $this->assertIsString($label, "{$section}.{$itemKey}: missing canonical label.");

                $labels["{$section}.{$itemKey}"] = trim($label);
            }
        }

        $this->assertCount(count($labels), array_unique(array_values($labels)), 'Each current Sidebar entry must have its own canonical label key.');

        return $labels;
    }
}

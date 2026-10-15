<?php

namespace Tests\Feature;

use Tests\TestCase;

class SharedEntityLookupContractTest extends TestCase
{
    public function test_shared_runtime_owns_accessible_keyboard_and_async_state_contract(): void
    {
        $runtime = file_get_contents(public_path('assets/admin/entity-lookup.js'));

        $this->assertIsString($runtime);
        foreach ([
            'data-foodex-entity-picker',
            'AbortController',
            'generation',
            'aria-activedescendant',
            'aria-selected',
            "event.key === 'ArrowDown'",
            "event.key === 'ArrowUp'",
            "event.key === 'Enter'",
            "event.key === 'Escape'",
            "event.key === 'Tab'",
            'data.foodexEntityOption',
            'next_page',
            'data.foodexEntityRetry',
            'foodex:entity-selected',
            'foodex:entity-cleared',
        ] as $contract) {
            $this->assertStringContainsString($contract, $runtime);
        }
    }

    public function test_sales_control_and_smart_ordering_share_the_entity_lookup_runtime(): void
    {
        $commercial = file_get_contents(resource_path('views/admin/commercial-dashboard.blade.php'));
        $smartOrdering = file_get_contents(resource_path('views/admin/smart-ordering-menu.blade.php'));
        $smartPicker = file_get_contents(resource_path('views/admin/_smart-ordering-product-picker.blade.php'));

        $this->assertIsString($commercial);
        $this->assertIsString($smartOrdering);
        $this->assertIsString($smartPicker);

        $this->assertStringContainsString("assets/admin/entity-lookup.js", $commercial);
        $this->assertStringContainsString('data-foodex-entity-picker', $commercial);
        $this->assertStringContainsString('data-foodex-entity-search', $commercial);
        $this->assertStringContainsString('data-foodex-entity-results', $commercial);
        $this->assertStringNotContainsString("const salesProductSelector = document.querySelector", $commercial);

        $this->assertStringContainsString("assets/admin/entity-lookup.js", $smartOrdering);
        $this->assertStringContainsString('data-foodex-entity-picker', $smartPicker);
        $this->assertStringContainsString('data-foodex-entity-search', $smartPicker);
        $this->assertStringContainsString('data-foodex-entity-id', $smartPicker);
        $this->assertStringContainsString('data-foodex-entity-results', $smartPicker);
        $this->assertStringNotContainsString('const productUrl = @json(route', $smartOrdering);
    }

    public function test_shared_picker_states_are_localized_in_arabic_and_english(): void
    {
        $enCommercial = file_get_contents(lang_path('en/commercial.php'));
        $arCommercial = file_get_contents(lang_path('ar/commercial.php'));
        $enSmart = file_get_contents(lang_path('en/smart_ordering_menu.php'));
        $arSmart = file_get_contents(lang_path('ar/smart_ordering_menu.php'));

        foreach ([$enCommercial, $arCommercial] as $catalog) {
            $this->assertIsString($catalog);
            $this->assertStringContainsString("'product_selector_loading'", $catalog);
            $this->assertStringContainsString("'product_selector_retry'", $catalog);
            $this->assertStringContainsString("'product_selector_inactive'", $catalog);
        }

        foreach ([$enSmart, $arSmart] as $catalog) {
            $this->assertIsString($catalog);
            foreach (['loading', 'empty', 'error', 'retry', 'selected', 'cleared', 'clear', 'inactive', 'load_more'] as $key) {
                $this->assertStringContainsString("'".$key."'", $catalog);
            }
        }
    }
}

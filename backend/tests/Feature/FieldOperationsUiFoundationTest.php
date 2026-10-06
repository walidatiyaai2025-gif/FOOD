<?php

namespace Tests\Feature;

use Tests\TestCase;

class FieldOperationsUiFoundationTest extends TestCase
{
    public function test_field_operations_primitives_extend_the_canonical_brand_component_layer(): void
    {
        $brand = file_get_contents(resource_path('views/admin/_brand-components.blade.php'));

        $this->assertIsString($brand);
        $this->assertStringContainsString('.foodex-ops-shell{', $brand);
        $this->assertStringContainsString('.foodex-ops-toolbar{', $brand);
        $this->assertStringContainsString('.foodex-ops-grid{', $brand);
        $this->assertStringContainsString('.foodex-ops-actions>summary{', $brand);
        $this->assertStringContainsString('.foodex-ops-menu{', $brand);
        $this->assertStringContainsString('.foodex-ops-detail-grid{', $brand);
        $this->assertStringContainsString('.foodex-ops-state{', $brand);
        $this->assertStringContainsString('width:var(--foodex-touch-target);height:var(--foodex-touch-target)', $brand);
        $this->assertStringContainsString('inset-inline-end:0', $brand);
    }

    public function test_field_operations_primitives_keep_existing_tabs_pagination_and_responsive_contracts(): void
    {
        $brand = file_get_contents(resource_path('views/admin/_brand-components.blade.php'));

        $this->assertIsString($brand);
        $this->assertStringContainsString('.foodex-tabs{', $brand);
        $this->assertStringContainsString('.pagination,.pager{', $brand);
        $this->assertStringContainsString('@media(max-width:1023px){.foodex-ops-toolbar{', $brand);
        $this->assertStringContainsString('@media(max-width:767px){.foodex-ops-toolbar,.foodex-ops-detail-grid{', $brand);
        $this->assertStringContainsString('.foodex-ops-grid .foodex-ops-hide-mobile{display:none}', $brand);
    }
}

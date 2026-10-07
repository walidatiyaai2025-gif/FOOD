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

    public function test_field_operations_business_workflows_use_lookups_and_map_edit_controls(): void
    {
        $view = file_get_contents(resource_path('views/admin/field-operations.blade.php'));

        $this->assertStringContainsString('data-van-transfer-lookup', $view);
        $this->assertStringContainsString('data-representative-lookup', $view);
        $this->assertStringContainsString('data-warehouse-lookup', $view);
        $this->assertStringContainsString('data-visit-customer', $view);
        $this->assertStringContainsString('data-store-lookup', $view);
        $this->assertStringContainsString('data-route-lookup', $view);
        $this->assertStringContainsString('data-order-lookup', $view);
        $this->assertStringContainsString('data-territory-lookup', $view);
        $this->assertStringContainsString('fieldops-coverage-undo', $view);
        $this->assertStringContainsString('{draggable:true', $view);
        $this->assertStringContainsString("marker.on('dblclick'", $view);
        $this->assertStringContainsString('validPolygon', $view);
        $this->assertStringContainsString('const loadSelectedGeometry=()=>', $view);
        $this->assertStringContainsString('feature.properties?.territory_id', $view);
        $this->assertStringContainsString('properties?.version', $view);
        $this->assertStringContainsString("select.addEventListener('change',loadSelectedGeometry)", $view);

        $this->assertStringNotContainsString('Transfer Van ID if loaded', $view);
        $this->assertStringNotContainsString('Representative user ID', $view);
        $this->assertStringNotContainsString('Customer ID', $view);
        $this->assertStringNotContainsString('Store ID (optional)', $view);
        $this->assertStringNotContainsString('placeholder="{{ $ar?\'كود المنطقة\':\'Territory key\' }}"', $view);
        $this->assertStringContainsString('$resolverNames->get($review->resolved_by)', $view);
        $this->assertStringContainsString('$territoryLabels->get($review->territory_key)', $view);
        $this->assertStringContainsString('$review->public_id', $view);
        $this->assertStringNotContainsString('<td>{{ $review->id }}</td>', $view);
        $this->assertStringNotContainsString('{{ $review->subject_type }} #{{ $review->subject_id }}', $view);
        $this->assertStringNotContainsString('{{ $review->resolved_by ? \'#\'.$review->resolved_by : \'—\' }}', $view);
    }
}

<?php

namespace Tests\Feature;

use App\Models\AddressQualityReview;
use App\Models\GeographyNode;
use App\Models\Role;
use App\Models\ServiceTerritory;
use App\Models\User;
use App\Models\Van;
use Database\Seeders\CoreReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FieldOperationsUiFoundationTest extends TestCase
{
    use RefreshDatabase;

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
        $this->assertStringContainsString("menu.style.position = 'fixed'", $brand);
        $this->assertStringContainsString("zIndex = '10050'", $brand);
        $this->assertStringContainsString("document.addEventListener('scroll', repositionOpenMenus, true)", $brand);
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

    public function test_super_admin_runtime_surfaces_render_lookup_map_and_compact_action_contracts(): void
    {
        $view = file_get_contents(resource_path('views/admin/field-operations.blade.php'));
        $this->assertIsString($view);
        $this->assertStringContainsString('data-order-lookup', $view);

        $this->seed(CoreReferenceSeeder::class);

        $admin = User::query()->create([
            'name' => 'Field Operations Runtime Owner',
            'email' => 'fieldops-runtime@example.test',
            'password' => 'password',
            'locale' => 'en',
            'is_active' => true,
        ]);
        $admin->roles()->attach(
            Role::query()->where('code', 'SUPER_ADMIN')->firstOrFail(),
        );

        $country = GeographyNode::query()->create([
            'type' => 'country',
            'code' => 'KW',
            'name_ar' => 'الكويت',
            'name_en' => 'Kuwait',
            'country_code' => 'KW',
            'is_active' => true,
        ]);

        $territory = ServiceTerritory::query()->create([
            'code' => 'EVID-KW-1',
            'name_ar' => 'منطقة دليل التشغيل',
            'name_en' => 'Runtime Evidence Territory',
            'country_node_id' => $country->id,
            'status' => 'active',
            'priority' => 10,
        ]);

        Van::query()->create([
            'public_id' => (string) Str::uuid(),
            'code' => 'EVID-VAN-1',
            'plate_number' => 'EVID-1037',
            'vehicle_type' => 'delivery',
            'status' => 'active',
        ]);

        AddressQualityReview::query()->create([
            'public_id' => (string) Str::uuid(),
            'subject_type' => 'customer_address',
            'subject_id' => 1037,
            'status' => 'unmapped',
            'quality_class' => 'needs_review',
            'confidence' => 0.42,
            'territory_key' => $territory->code,
        ]);

        $this->actingAs($admin)
            ->get('/admin/field-operations/vans')
            ->assertOk()
            ->assertSee('data-van-transfer-lookup', false)
            ->assertSee('foodex-ops-actions', false)
            ->assertSee('⋮', false);

        $this->actingAs($admin)
            ->get('/admin/field-operations/assignments')
            ->assertOk()
            ->assertSee('data-representative-lookup', false)
            ->assertSee('data-warehouse-lookup', false);

        $this->actingAs($admin)
            ->get('/admin/field-operations/visits')
            ->assertOk()
            ->assertSee('data-visit-customer', false)
            ->assertSee('data-store-lookup', false)
            ->assertSee('data-route-lookup', false);

        $this->actingAs($admin)
            ->get('/admin/field-operations/territories')
            ->assertOk()
            ->assertSee('fieldops-coverage-map', false)
            ->assertSee('fieldops-coverage-undo', false)
            ->assertSee('fieldops-coverage-clear', false)
            ->assertSee('data-advanced-geojson', false)
            ->assertSee('validPolygon', false)
            ->assertSee('{draggable:true', false)
            ->assertSee("marker.on('dblclick'", false);

        $this->actingAs($admin)
            ->get('/admin/field-operations/address-quality')
            ->assertOk()
            ->assertSee('data-territory-lookup', false)
            ->assertSee($territory->code);
    }

    public function test_routing_normal_flow_uses_structured_controls_and_fences_json_to_super_admin_advanced(): void
    {
        $view = file_get_contents(resource_path('views/admin/field-operations.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString('data-routing-rules', $view);
        $this->assertStringContainsString('name="rules[0][name]"', $view);
        $this->assertStringContainsString('name="rules[0][condition_key]"', $view);
        $this->assertStringContainsString('name="rules[0][action_key]"', $view);
        $this->assertStringContainsString('data-routing-add-rule', $view);
        $this->assertStringContainsString('data-advanced-routing-json', $view);
        $this->assertStringContainsString('@if($isSuper)', $view);
        $this->assertStringContainsString('name="input_keys[]"', $view);
        $this->assertStringContainsString('name="input_values[]"', $view);
        $this->assertStringContainsString('name="scope_keys[]"', $view);
        $this->assertStringContainsString('name="scope_values[]"', $view);
        $this->assertStringNotContainsString('<textarea name="input_json"', $view);
        $this->assertStringNotContainsString('<textarea name="scope_json"', $view);
        $this->assertStringNotContainsString('<textarea name="rules_json" rows="6" required>', $view);
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
        $this->assertStringContainsString('setView([26.8206,30.8025],6)', $view);
        $this->assertStringNotContainsString('setView([29.3759,47.9774]', $view);
        $this->assertStringContainsString('map.fitBounds(existingLayer.getBounds()', $view);
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

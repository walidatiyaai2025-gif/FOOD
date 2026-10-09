<?php

namespace Tests\Feature;

use Illuminate\Support\Arr;
use Tests\TestCase;

class DashboardUxSweepContractTest extends TestCase
{
    public function test_shared_dashboard_runtime_owns_operational_modals_and_directional_pagination(): void
    {
        $components = file_get_contents(resource_path('views/admin/_brand-components.blade.php'));

        $this->assertStringContainsString('foodex-operational-modal-runtime', $components);
        $this->assertStringContainsString('details[data-foodex-operational-modal]', $components);
        $this->assertStringContainsString('html[dir=rtl] .pagination svg', $components);
        $this->assertStringContainsString('nav[role="navigation"] svg{width:18px!important', $components);
        $this->assertStringContainsString('.foodex-pagination{display:flex', $components);
        $this->assertStringContainsString('.foodex-modal-backdrop[hidden]{display:none!important}', $components);

        $provider = file_get_contents(app_path('Providers/AppServiceProvider.php'));
        $this->assertStringContainsString("Paginator::defaultView('pagination.foodex')", $provider);
        $this->assertStringContainsString("Paginator::defaultSimpleView('pagination.foodex-simple')", $provider);
    }

    public function test_dashboard_mutation_surfaces_use_shared_operational_modal_contract(): void
    {
        $surfaces = [
            'security.blade.php',
            'notifications.blade.php',
            'notification-campaigns.blade.php',
            'customer-360-show.blade.php',
            'field-operations.blade.php',
            'b2b-workspace.blade.php',
            'b2c-workspace.blade.php',
            'live-ads.blade.php',
            'coupons.blade.php',
        ];

        foreach ($surfaces as $surface) {
            $content = file_get_contents(resource_path('views/admin/'.$surface));
            $this->assertStringContainsString(
                'data-foodex-operational-modal',
                $content,
                $surface.' must use the shared operational modal contract.',
            );
        }
    }

    public function test_flash_offer_authoring_is_guided_and_product_context_scoped(): void
    {
        $view = file_get_contents(resource_path('views/admin/commercial-dashboard.blade.php'));
        $controller = file_get_contents(app_path('Http/Controllers/Admin/CommercialDashboardController.php'));

        $this->assertStringContainsString('data-flash-wizard-progress', $view);
        $this->assertStringContainsString('data-flash-wizard-step="0"', $view);
        $this->assertStringContainsString('data-flash-wizard-step="4"', $view);
        $this->assertStringContainsString('data-flash-product-context', $view);
        $this->assertStringContainsString('data-product-context=', $view);
        $this->assertStringContainsString('\'catalogs.channel as catalog_channel\'', $controller);
    }

    public function test_dashboard_review_metadata_does_not_render_raw_internal_enums(): void
    {
        $view = file_get_contents(resource_path('views/admin/field-operations.blade.php'));

        $this->assertStringContainsString('field_operations.resolution_sources.', $view);
        $this->assertStringContainsString('field_operations.quality_classes.', $view);
        $this->assertStringNotContainsString('Str::headline((string)$event->event_type', $view);
        $this->assertStringNotContainsString('{{ $review->quality_class }}', $view);
    }

    public function test_flash_wizard_translation_keys_have_ar_en_parity(): void
    {
        $english = require lang_path('en/commercial.php');
        $arabic = require lang_path('ar/commercial.php');
        $keys = [
            'flash.product_context',
            'flash.context_all',
            'flash.context_retail',
            'flash.context_wholesale',
            'flash.wizard_previous',
            'flash.wizard_next',
        ];

        foreach ($keys as $key) {
            $this->assertTrue(Arr::has($english, $key), 'Missing English key: '.$key);
            $this->assertTrue(Arr::has($arabic, $key), 'Missing Arabic key: '.$key);
            $this->assertNotSame('', trim((string) Arr::get($english, $key)));
            $this->assertNotSame('', trim((string) Arr::get($arabic, $key)));
        }
    }
}

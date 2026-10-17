<?php

namespace Tests\Feature;

use Tests\TestCase;

final class AdminExecutiveOverviewContractTest extends TestCase
{
    public function test_shell_composes_executive_overview_only_for_the_canonical_platform_root(): void
    {
        $shell = file_get_contents(resource_path('views/admin/shell.blade.php'));

        $this->assertIsString($shell);
        $this->assertStringContainsString('@if($activeChannel === null)', $shell);
        $this->assertStringContainsString("@include('admin._executive-overview')", $shell);
        $this->assertStringContainsString("__('admin.shell_ready')", $shell);
        $this->assertStringContainsString("@include('admin._global-search'", $shell);
        $this->assertStringContainsString("@include('admin._live-notifications')", $shell);
        $this->assertStringContainsString("@include('admin._sidebar')", $shell);
    }

    public function test_executive_overview_uses_authorized_navigation_and_truthful_service_states(): void
    {
        $view = file_get_contents(resource_path('views/admin/_executive-overview.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString('$groups = collect($navGroups ?? [])', $view);
        $this->assertStringContainsString("['overview', 'administration']", $view);
        $this->assertStringContainsString("firstWhere('key', 'enterprise_action_center')", $view);
        $this->assertStringContainsString("firstWhere('key', 'administration_hub')", $view);
        $this->assertStringContainsString("firstWhere('key', 'smart_ordering')", $view);
        $this->assertStringContainsString("Gate::forUser($user)->allows('platform.manage')", $view);
        $this->assertStringContainsString("Route::has('admin.enterprise-system-health.index')", $view);
        $this->assertStringContainsString("__('admin_overview.restaurant_saas_help')", $view);
        $this->assertStringContainsString("__('admin_overview.no_synthetic_metrics')", $view);
        $this->assertStringContainsString('data-foodex-module-state="empty"', $view);
        $this->assertStringNotContainsString('href="#"', $view);
        $this->assertStringNotContainsString('dashboard-v2', $view);
        $this->assertStringNotContainsString('$810,000', $view);
        $this->assertStringNotContainsString('1,248 orders', $view);
        $this->assertStringNotContainsString('18 low-stock products', $view);
        $this->assertStringNotContainsString('95% delivery performance', $view);
    }

    public function test_executive_overview_uses_foodex_tokens_and_responsive_touch_contract(): void
    {
        $view = file_get_contents(resource_path('views/admin/_executive-overview.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString('var(--foodex-green-soft)', $view);
        $this->assertStringContainsString('var(--foodex-border)', $view);
        $this->assertStringContainsString('min-height:44px', $view);
        $this->assertStringContainsString('@media(max-width:1279px)', $view);
        $this->assertStringContainsString('@media(max-width:1023px)', $view);
        $this->assertStringContainsString('@media(max-width:767px)', $view);
        $this->assertStringContainsString('@media(max-width:389px)', $view);
        $this->assertStringNotContainsString('linear-gradient', $view);
        $this->assertStringNotContainsString('#073c2d', $view);
    }

    public function test_executive_overview_translation_catalogs_have_ar_en_key_parity(): void
    {
        $english = require lang_path('en/admin_overview.php');
        $arabic = require lang_path('ar/admin_overview.php');

        $this->assertIsArray($english);
        $this->assertIsArray($arabic);
        $this->assertSame(array_keys($english), array_keys($arabic));
        $this->assertSame([], array_filter($english, static fn ($value): bool => ! is_string($value) || trim($value) === ''));
        $this->assertSame([], array_filter($arabic, static fn ($value): bool => ! is_string($value) || trim($value) === ''));
    }
}

<?php

namespace Tests\Feature;

use Tests\TestCase;

final class AdminCompactHeaderContractTest extends TestCase
{
    public function test_shared_brand_tokens_define_compact_header_geometry(): void
    {
        $brand = file_get_contents(resource_path('views/admin/_brand.blade.php'));

        $this->assertIsString($brand);
        $this->assertStringContainsString('--foodex-header-height:44px', $brand);
        $this->assertStringContainsString('--foodex-header-height-tablet:44px', $brand);
        $this->assertStringContainsString('--foodex-header-height-mobile:56px', $brand);
        $this->assertStringContainsString('--foodex-header-control-height:32px', $brand);
        $this->assertStringContainsString('--foodex-header-control-height-tablet:32px', $brand);
        $this->assertStringContainsString('--foodex-header-control-height-mobile:44px', $brand);
        $this->assertStringContainsString('--foodex-header-icon-size:16px', $brand);
    }

    public function test_shared_global_header_and_page_toolbar_contract_are_distinct(): void
    {
        $components = file_get_contents(resource_path('views/admin/_brand-components.blade.php'));

        $this->assertIsString($components);
        $this->assertStringContainsString('.foodex-global-header{', $components);
        $this->assertStringContainsString('height:var(--foodex-header-height)', $components);
        $this->assertStringContainsString('.foodex-page-toolbar{', $components);
        $this->assertStringContainsString('font-size:var(--foodex-text-lg)!important', $components);
        $this->assertStringContainsString('flex-wrap:nowrap', $components);
        $this->assertStringContainsString('position:fixed;z-index:10110', $components);
        $this->assertStringContainsString('height:var(--foodex-header-height-mobile);min-height:var(--foodex-header-height-mobile);max-height:var(--foodex-header-height-mobile)', $components);
        $this->assertStringContainsString('min-height:var(--foodex-touch-target)!important', $components);
        $this->assertStringNotContainsString('foodex-page-header', $components);
    }

    public function test_header_search_notifications_and_account_controls_use_compact_tokens(): void
    {
        $search = file_get_contents(resource_path('views/admin/_global-search.blade.php'));
        $notifications = file_get_contents(resource_path('views/admin/_live-notifications.blade.php'));
        $account = file_get_contents(resource_path('views/admin/_account-menu.blade.php'));
        $searchScript = file_get_contents(public_path('assets/admin/global-search.js'));

        $this->assertIsString($search);
        $this->assertIsString($notifications);
        $this->assertIsString($account);
        $this->assertIsString($searchScript);

        $this->assertStringContainsString('input.foodex-global-search-input{height:var(--foodex-header-control-height)!important;min-height:var(--foodex-header-control-height)!important', $search);
        $this->assertStringContainsString('height:var(--foodex-header-control-height-mobile)!important;min-height:var(--foodex-header-control-height-mobile)!important', $search);
        $this->assertStringContainsString('font-size:16px', $search);

        $this->assertStringContainsString('width:var(--foodex-header-control-height)', $notifications);
        $this->assertStringContainsString('width:var(--foodex-touch-target)', $notifications);
        $this->assertStringContainsString("@include('admin._account-menu'", $notifications);

        $this->assertStringContainsString('width:var(--foodex-header-control-height)', $account);
        $this->assertStringContainsString('width:var(--foodex-touch-target)', $account);

        $this->assertStringContainsString("event.key === 'Enter'", $searchScript);
        $this->assertStringContainsString('const selected = activeIndex >= 0 ? rows[activeIndex] : rows[0];', $searchScript);
        $this->assertStringContainsString('await search(term, currentGeneration);', $searchScript);
        $this->assertStringContainsString('firstResult.click();', $searchScript);
    }

    public function test_canonical_admin_shell_uses_shared_global_header_partial(): void
    {
        $shell = file_get_contents(resource_path('views/admin/shell.blade.php'));
        $header = file_get_contents(resource_path('views/admin/_global-header.blade.php'));

        $this->assertIsString($shell);
        $this->assertIsString($header);
        $this->assertSame(1, substr_count($shell, "@include('admin._global-header')"));
        $this->assertStringNotContainsString("@include('admin._global-search'", $shell);
        $this->assertStringNotContainsString("@include('admin._live-notifications'", $shell);
        $this->assertStringContainsString('data-foodex-global-header', $header);
        $this->assertStringContainsString("@include('admin._global-search'", $header);
        $this->assertStringContainsString("@include('admin._live-notifications'", $header);
    }

    public function test_dashboard_contract_documents_single_global_header_strategy(): void
    {
        $contract = file_get_contents(base_path('../docs/design-reference/DASHBOARD_UI_UX_CONTRACT.md'));

        $this->assertIsString($contract);
        $this->assertStringContainsString('## 13B. Single Global Admin Header System', $contract);
        $this->assertStringContainsString('desktop global header chrome targets **44 px** height', $contract);
        $this->assertStringContainsString('one shared `admin._global-header`', $contract);
        $this->assertStringContainsString('page content uses `.foodex-page-toolbar`', $contract);
    }
}

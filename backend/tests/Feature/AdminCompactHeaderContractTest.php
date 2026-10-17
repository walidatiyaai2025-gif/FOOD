<?php

namespace Tests\Feature;

use Tests\TestCase;

final class AdminCompactHeaderContractTest extends TestCase
{
    public function test_shared_brand_tokens_define_compact_header_geometry(): void
    {
        $brand = file_get_contents(resource_path('views/admin/_brand.blade.php'));

        $this->assertIsString($brand);
        $this->assertStringContainsString('--foodex-header-height:32px', $brand);
        $this->assertStringContainsString('--foodex-header-height-tablet:34px', $brand);
        $this->assertStringContainsString('--foodex-header-height-mobile:44px', $brand);
        $this->assertStringContainsString('--foodex-header-control-height:24px', $brand);
        $this->assertStringContainsString('--foodex-header-control-height-tablet:28px', $brand);
        $this->assertStringContainsString('--foodex-header-control-height-mobile:44px', $brand);
        $this->assertStringNotContainsString('--foodex-header-height:76px', $brand);
    }

    public function test_shared_page_header_contract_is_dense_without_wrapped_action_walls(): void
    {
        $components = file_get_contents(resource_path('views/admin/_brand-components.blade.php'));

        $this->assertIsString($components);
        $this->assertStringContainsString('height:var(--foodex-header-height)!important', $components);
        $this->assertStringContainsString('font-size:var(--foodex-header-title-size)!important', $components);
        $this->assertStringContainsString('white-space:nowrap', $components);
        $this->assertStringContainsString('text-overflow:ellipsis', $components);
        $this->assertStringContainsString('flex-wrap:nowrap', $components);
        $this->assertStringContainsString('height:var(--foodex-header-height-mobile)!important', $components);
        $this->assertStringContainsString('min-height:var(--foodex-touch-target)!important', $components);
        $this->assertStringContainsString('flex-direction:row!important', $components);
    }

    public function test_header_search_notifications_and_account_controls_use_compact_tokens(): void
    {
        $search = file_get_contents(resource_path('views/admin/_global-search.blade.php'));
        $notifications = file_get_contents(resource_path('views/admin/_live-notifications.blade.php'));
        $account = file_get_contents(resource_path('views/admin/_account-menu.blade.php'));

        $this->assertIsString($search);
        $this->assertIsString($notifications);
        $this->assertIsString($account);

        $this->assertStringContainsString('height:var(--foodex-header-control-height)', $search);
        $this->assertStringContainsString('height:var(--foodex-header-control-height-mobile)', $search);
        $this->assertStringContainsString('font-size:16px', $search);

        $this->assertStringContainsString('width:var(--foodex-header-control-height)', $notifications);
        $this->assertStringContainsString('width:var(--foodex-touch-target)', $notifications);

        $this->assertStringContainsString('width:var(--foodex-header-control-height)', $account);
        $this->assertStringContainsString('width:var(--foodex-touch-target)', $account);
    }

    public function test_canonical_admin_shell_uses_single_row_compact_header_strategy(): void
    {
        $shell = file_get_contents(resource_path('views/admin/shell.blade.php'));

        $this->assertIsString($shell);
        $this->assertStringContainsString('height:var(--foodex-header-height)!important', $shell);
        $this->assertStringContainsString('height:var(--foodex-header-height-tablet)!important', $shell);
        $this->assertStringContainsString('height:var(--foodex-header-height-mobile)!important', $shell);
        $this->assertStringContainsString('grid-template-columns:minmax(70px,.72fr) minmax(105px,1fr) auto', $shell);
        $this->assertStringContainsString('.user-card>strong,.user-card>span,.roles{display:none}', $shell);
        $this->assertStringContainsString("data-foodex-dashboard-header-search", $shell);
        $this->assertStringContainsString("@include('admin._live-notifications')", $shell);
    }

    public function test_dashboard_contract_documents_compact_header_strategy(): void
    {
        $contract = file_get_contents(base_path('../docs/design-reference/DASHBOARD_UI_UX_CONTRACT.md'));

        $this->assertIsString($contract);
        $this->assertStringContainsString('## 13B. Compact Admin Header System', $contract);
        $this->assertStringContainsString('desktop header chrome targets **32 px** height', $contract);
        $this->assertStringContainsString('mobile web uses **44 px**', $contract);
    }
}

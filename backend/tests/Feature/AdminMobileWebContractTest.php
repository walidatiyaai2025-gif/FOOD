<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminMobileWebContractTest extends TestCase
{
    public function test_authoritative_mobile_web_contract_covers_existing_and_future_dashboard_pages(): void
    {
        $contract = file_get_contents(dirname(base_path()).'/docs/design-reference/DASHBOARD_MOBILE_WEB_CONTRACT.md');

        $this->assertIsString($contract);
        $this->assertStringContainsString('every existing Dashboard page and every future Dashboard page', $contract);
        $this->assertStringContainsString('320 px', $contract);
        $this->assertStringContainsString('430 px', $contract);
        $this->assertStringContainsString('No page-level horizontal overflow', $contract);
        $this->assertStringContainsString('off-canvas drawer', $contract);
        $this->assertStringContainsString('record cards', $contract);
        $this->assertStringContainsString('data-foodex-mobile-table="scroll"', $contract);
        $this->assertStringContainsString('same route, permissions, authoritative data and business actions as desktop', $contract);
    }

    public function test_shared_admin_components_enforce_mobile_web_foundation(): void
    {
        $shared = file_get_contents(resource_path('views/admin/_brand-components.blade.php'));

        $this->assertIsString($shared);
        $this->assertStringContainsString('id="foodex-mobile-web-foundation"', $shared);
        $this->assertStringContainsString('font-size:16px', $shared);
        $this->assertStringContainsString('.foodex-mobile-action-rail', $shared);
        $this->assertStringContainsString('.foodex-mobile-filter-sheet', $shared);
        $this->assertStringContainsString('.foodex-mobile-sticky-actions', $shared);
        $this->assertStringContainsString('table[data-foodex-mobile-table-ready="1"] tbody tr', $shared);
        $this->assertStringContainsString("table.dataset.foodexMobileTableReady = '1'", $shared);
        $this->assertStringContainsString("table.dataset.foodexMobileTable === 'scroll'", $shared);
        $this->assertStringContainsString("MutationObserver", $shared);
    }

    public function test_shared_sidebar_is_a_mobile_off_canvas_drawer_with_accessible_dismissal(): void
    {
        $sidebar = file_get_contents(resource_path('views/admin/_sidebar.blade.php'));

        $this->assertIsString($sidebar);
        $this->assertStringContainsString('.foodex-mobile-web-bar', $sidebar);
        $this->assertStringContainsString('.foodex-mobile-nav-launcher', $sidebar);
        $this->assertStringContainsString('.foodex-mobile-nav-backdrop', $sidebar);
        $this->assertStringContainsString('body.foodex-mobile-nav-open', $sidebar);
        $this->assertStringContainsString("event.key === 'Escape'", $sidebar);
        $this->assertStringContainsString("launcher.setAttribute('aria-expanded'", $sidebar);
        $this->assertStringContainsString("setDrawer(false)", $sidebar);
        $this->assertStringContainsString("matchMedia('(max-width:767px)')", $sidebar);
    }
}

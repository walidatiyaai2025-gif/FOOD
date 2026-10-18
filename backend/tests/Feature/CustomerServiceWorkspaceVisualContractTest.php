<?php

namespace Tests\Feature;

use Tests\TestCase;

final class CustomerServiceWorkspaceVisualContractTest extends TestCase
{
    public function test_customer_service_workspace_uses_compact_single_row_kpis_with_icons_and_counters(): void
    {
        $view = file_get_contents(resource_path('views/admin/customer-service-workspace.blade.php'));

        $this->assertIsString($view);
        $this->assertStringNotContainsString('class="foodex-page-toolbar"', $view);
        $this->assertStringContainsString('grid-auto-flow:column', $view);
        $this->assertStringContainsString('grid-auto-columns:minmax(132px,165px)', $view);
        $this->assertStringContainsString('class="cs-kpi-icon"', $view);
        $this->assertStringContainsString("@include('admin._premium-icon',['name'=>\$presentation['icon']])", $view);
        $this->assertStringContainsString('data-tone="{{ $presentation[\'tone\'] }}"', $view);
        $this->assertStringContainsString('data-cs-counter', $view);
        $this->assertStringContainsString('data-count-target', $view);
        $this->assertStringContainsString("prefers-reduced-motion: reduce", $view);
        $this->assertStringContainsString('window.requestAnimationFrame(tick)', $view);
        $this->assertStringContainsString('@keyframes csKpiStripe', $view);
        $this->assertStringContainsString('@keyframes csKpiEnter', $view);
        $this->assertStringContainsString('.cs-kpi::before{content:""', $view);
        $this->assertStringContainsString('animation:csKpiStripe 2.4s linear infinite', $view);
        $this->assertStringContainsString('@media(prefers-reduced-motion:reduce)', $view);
        $this->assertStringContainsString('@keyframes csBarFill', $view);
        $this->assertStringContainsString('@keyframes csBarFlow', $view);
        $this->assertStringContainsString('--cs-bar-width:', $view);
        $this->assertStringContainsString('background-size:220% 100%', $view);
        $this->assertStringContainsString('class="foodex-card cs-filter-strip"', $view);
        $this->assertStringContainsString('.cs-kpis + .cs-filter-strip{margin-top:calc(-1 * var(--foodex-space-5) + 4px)}', $view);
        $this->assertStringContainsString('.cs-filter{display:flex', $view);
        $this->assertStringContainsString('.cs-filter select{field-sizing:content}', $view);
        $this->assertStringContainsString('max-width:180px', $view);
        $this->assertStringContainsString('class="cs-visually-hidden"', $view);
        $this->assertStringNotContainsString("customer_service_workspace.dashboard.help", $view);
        $this->assertStringNotContainsString('id="customer-service-search-title"', $view);
        $this->assertStringContainsString("customer_service_workspace.search_help", $view);
        $this->assertStringContainsString('role="tablist"', $view);
        $this->assertStringContainsString("old('role_code')) ? 'team' : 'analytics'", $view);
        $this->assertLessThan(
            strpos($view, 'data-cs-tab="cases"'),
            strpos($view, 'data-cs-tab="analytics"'),
        );
        $this->assertStringContainsString('data-cs-tab="cases"', $view);
        $this->assertStringContainsString('data-cs-tab="analytics"', $view);
        $this->assertStringContainsString("@php(\$caseCount = (int) \$cases->total())", $view);
        $this->assertStringContainsString("cs-tab-count {{ \$caseCount === 0 ? 'ok' : 'danger' }}", $view);
        $this->assertStringContainsString('.cs-tab-count.ok{background:#DCFCE7', $view);
        $this->assertStringContainsString('.cs-tab-count.danger{background:#FEE2E2', $view);
        $this->assertStringContainsString("@php(\$customerCount = (int) (\$dashboard['total_customers'] ?? 0))", $view);
        $this->assertStringContainsString('cs-tab-count info', $view);
        $this->assertStringContainsString('.cs-tab-count.info{background:#DBEAFE', $view);
        $this->assertStringContainsString('data-cs-tab="inbox"', $view);
        $this->assertStringContainsString('data-cs-panel="inbox"', $view);
        $this->assertStringContainsString("@php(\$inboxUnreadCount = (int) (\$inboxSummary['unread_count'] ?? 0))", $view);
        $this->assertStringContainsString('@if($inboxUnreadCount > 0)<span class="cs-tab-count unread"', $view);
        $this->assertStringContainsString('.cs-tab-count.unread{background:#FEE2E2', $view);
        $this->assertStringContainsString('customer_service_workspace.inbox.open_full', $view);
        $this->assertStringContainsString('route(\'admin.whatsapp-inbox.index\',[\'conversation\'=>$conversation->id])', $view);
        $this->assertStringContainsString('data-case-modal-link', $view);
        $this->assertStringContainsString('data-case-modal-content', $view);
        $this->assertStringContainsString('data-case-modal-close', $view);
        $this->assertStringContainsString('data-case-list-url=', $view);
        $this->assertStringContainsString("fetch(link.href", $view);
        $this->assertStringContainsString("body.replaceChildren(content);", $view);
        $this->assertStringContainsString("history.replaceState(null, '', listUrl + '#cs-tab-cases');", $view);
        $this->assertStringContainsString('body.cs-case-modal-open{overflow:hidden}', $view);
        $this->assertStringContainsString('data-cs-panel="cases"', $view);
        $this->assertStringContainsString('data-cs-panel="analytics"', $view);
        $this->assertStringContainsString('.cs-tab-panel{display:grid;gap:16px;min-width:0;width:100%;max-width:100%}', $view);
        $this->assertStringContainsString('.cs-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}', $view);
        $this->assertStringContainsString('history.replaceState', $view);

        foreach ([
            'active_customers',
            'new_customers',
            'repeat_customers',
            'open_cases',
            'overdue_sla',
            'high_risk_customers',
            'unresolved_conversations',
            'recent_complaints',
        ] as $metricKey) {
            $this->assertStringContainsString("'{$metricKey}' => ['icon' =>", $view);
        }
    }
}

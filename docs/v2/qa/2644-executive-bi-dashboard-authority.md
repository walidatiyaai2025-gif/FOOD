# V2 #2644 / P314 — Executive BI Dashboard: canonical Sidebar and scoped KPI evidence

## Source and placement

Source: FOODEX Unified RBAC/ERP/Sidebar discussion (2026-11-09), P314 executive management dashboards; parent #2503. Intended role: authorized globally scoped ERP reporting user / Super Admin with effective enterprise_bi entitlement. Goal: inspect authorized KPI cards, period trends, the BI reporting charts and source drill-downs in the same management workspace. There must be no invented financial authority or orphaned page.

**Reuse** the existing Analytics → BI item (key erp_bi) and ERP Center BI card. Both resolve to admin.erp.bi → EnterpriseBiController::index → admin.enterprise-bi including shared _enterprise-bi-overview. Its ExecutiveBiService/ExecutiveKpiRegistry supplies real registered report-backed KPI cards, scoped store/entity, currency, period, data-freshness/empty/error states. Pattern: responsive Executive Dashboard with embedded Data Explorer and source drill-down. No separate top-level Sidebar group, new endpoint, duplicate CSS/rendering or alternate access policy.

For desktop, tablet and mobile web, preserve the shared Dashboard CSS/foodex-module-kpis layout, data-bi-chart component, common global header and off-canvas Sidebar behavior. Arabic RTL / English LTR use existing enterprise_bi_ui translations.

## Tests and authorizations

New backend/tests/Feature/V2ExecutiveBiSidebarWorkflowsTest.php proves via full authenticated Laravel GET:

- AR/RTL and EN/LTR: authorized actor discovers the BI link and opens the actual workbench with overview and chart primitives; the orders KPI is computed from store-scoped actual order data (one order, KWD 24.000).
- Authorized-store scope versus a forged other-store dashboard_store parameter: the submitted foreign scope is never selected, and the foreign KWD 900.000 order cannot appear in current KPI; no escalation from option presence.
- Malformed currency is normalized safely; no invented result and insufficient-history forecast remains unavailable.
- Effective entitlement missing, wrong manifest readiness version, module disabled or roleless actor: canonical route is blocked HTTP 403, and the Sidebar link is unavailable for unentitled/unready users. These are server guards, not merely visual hiding.
- Existing role/permission/report/maker-checker policies are retained; no fresh ALLOW or bypass.

Source foundational coverage: EnterpriseExecutiveBiFoundationTest (registered KPI definitions, accurate trends, unentitled read-model short-circuit and explicit projection staleness), plus BI P312 HTTP route parity (#2642). These tests add UI-journey integration rather than duplicating the source data math.

## Impact classification

- Backend: test-only, exercises existing Dashboard BI GET and report services; no runtime source changes.
- Dashboard: existing executive KPI and charts under BI, accessible from current business navigation.
- Customer App: not affected, no API, pricing, orders, customer identity, response shape or Flutter changes.
- Driver App: not affected, no B2C dispatch, delivery, GPS or notification contract changes.
- Van App: not affected, no B2B visit, routing, stock or Van-client contract changes.

All required checks (Repository Policy, V2 Isolation Gate, exact-head Required CI including Laravel, AR/EN, security and MySQL/Redis install/upgrade/recovery) must pass before PR merge only into Foodex_V2. No main, tag, release or production changes. Browser/device/manual end-stage UAT is deferred under parent #2503 and #2750, not claimed here. MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL.

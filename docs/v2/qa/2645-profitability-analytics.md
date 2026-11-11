# V2 #2645 / P315 — Profitability analytics runtime and Sidebar authority

## Canonical business journey

- Source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx / P315 (Reports & BI); parent #2503.
- Domain: Reports & BI; entity: store-scoped, currency-safe commercial profitability fact; persona: entitled BUSINESS_ANALYST or another active globally authorized reporting role.
- Goal: inspect order count, net revenue, historical cost, margin and unvalued lines for an authorized store and chosen time period; never aggregate different currencies silently.
- Placement strategy: **Reuse** existing Analytics → BI Sidebar entry (key erp_bi) / ERP Center BI card → route admin.erp.bi → EnterpriseBiController::index → admin.enterprise-bi. Choose report commercial.profitability from the existing report catalog. This is the P315 functional child within P312's existing workbench; no second screen or dead Sidebar leaf is created.
- Presentation: existing responsive Dashboard Data Explorer/Workbench, AR/RTL and EN/LTR on mobile web/tablet/desktop; existing report filter controls and localizations. No new CSS or markup, no unregistered route or manual typing of internal store IDs.

## Confirmed issue and fix

CommercialProfitabilityReadModel::mapRow returns the canonical report measure `orders`, whereas ReportBuilderExecutor::projectRow previously read `order_count` unconditionally for every report's `orders` request. Executing P315 with its default/selected orders measure raised ValidationException, handled by the UI as a generic run error. The same executor must still accept `order_count` from OrdersSummaryReadModel.

The narrowly scoped compatibility fix in ReportBuilderExecutor accepts `order_count` when emitted and otherwise accepts the canonical `orders` source key, preserving the external report measure name `orders`, report output, Order Summary behavior, API/OpenAPI and all existing read models.

Existing module readiness middleware, Sidebar authority, BI page controller entitlement guard (P312, PR #3226) and ReportAccessResolver remain unchanged. The canonical report's mandatory store scope, entitlement, permission and currency grouping/constraint continue to be checked by the existing services.

## Source-backed automated acceptance

New V2ProfitabilityAnalyticsSidebarContractTest includes:

1. AR and EN, RTL/LTR: discover existing BI Sidebar link, open and execute commercial.profitability via real authenticated Laravel HTTP with explicit currency, read `orders` / net revenue / cost / margin / unvalued lines, and verify actual values. A second store's huge numbers must not leak into the result.
2. Requested foreign store scope: only authorized local scope is selected; report groups KWD and USD separately rather than adding them; unknown historical cost yields null cost/margin and a positive unvalued-lines count rather than fabricated profitability.
3. Missing entitlement, explicit multi-role permission DENY, unapproved exact module version, disabled module, roleless access all produce direct HTTP 403, independent of the UI hiding the menu.

Related read model freshness and idempotence tests in EnterpriseProfitabilityReadModelTest and the P312 BI route parity tests continue to run as part of required CI. No source cost valuation policy or financial approval thresholds invented.

## Changes and mobile effects

- Backend: compatibility normalization in ReportBuilderExecutor plus issue-scoped integration regression. Existing web route only; no API schema change.
- Dashboard: real working profitability report inside existing BI, AR/EN, read-only filters and analytics. No change to route tree, layout or permissions model.
- Customer app: no impact — no commerce API, order/pricing/customer contract or Flutter source changed.
- Driver app: no impact — no delivery assignment, payment, GPS or notification contract changed.
- Van app: no impact — no B2B visit, route, stock, dispatch or Van Flutter contract changed.

One issue-scoped PR into Foodex_V2 only after exact-head Repository Policy, V2 Isolation and Required CI including backend full suite and MySQL/Redis install-upgrade-recovery succeed. Main changes, tags, release and production deployments are excluded. MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL under #2503 / #2750, not claimed as performed.

# V2 #2522 — P192 Sales / المبيعات Sidebar group

## Source and placement

- Source: FOODEX Unified RBAC ERP Sidebar Discussion 2026-11-09, Part 2 §4, P192; owner #2522, parent #2503, integration Foodex_V2 ONLY.
- Business domain: Sales. Entity: scoped sales quotations; actors: authenticated Wholesale B2B and assigned Retail sales. Goal: open real sales quotations, review/create/issue through the existing `SalesQuotationController`. Workflow: recurring list/details with existing full page and compact shared navigation.
- Decision: **Reuse** the canonical `admin.sales-quotations.index` from P193, not a new screen. Group placement: `SIDEBAR` / `sales`, item `sales_quotations`, permission `orders.view`. Existing `orders.manage` is still required to create or issue. HTTP authority: `OperationalTenantScope::allowedStoreIds` and `assertStore`, missing ability 403, foreign store 404, active actor only.
- Desktop, tablet and mobile web use the same existing accessible Dashboard shell/off-canvas drawer, with `admin.nav_groups.sales` AR `المبيعات` / RTL and EN `Sales` / LTR. Parent group is not a link; empty group is hidden. Back/fallback: Dashboard Sidebar and existing quotations workspace.
- Keep `order_operations` in its canonical `operations` group: P194 sales orders and P207 operational order management share a real route, so duplicate Sidebar entries are not created. Contracts/pricing P195 belongs to #2525; approved discounts P196 remain contextual under Orders; exception approvals P197 belong to #2527 and current Sales Control review tab. Pending routes are hidden, not fabricated.

## Cross-surface / security / acceptance

| Surface | Result |
| --- | --- |
| Laravel Backend | Existing routes, role/tenant scopes, HTTP and pricing logic unchanged; only Sidebar registry/composition |
| Dashboard | Sales business group with authorized quotations, existing responsive AR/EN and RTL/LTR shell |
| Customer | No API/quote/checkout/pricing change; no mobile issue needed |
| Driver | No dispatch, order/route, identity or notification change |
| Van | No consignment, dispatch, Van direct-sale or offline API change |

- No security change or visual grant: `AdminSidebarManager` cannot grant `orders.view`; user, scoped channel/store and active role enforce the destination; permissions create/issue unchanged.
- Separate P192 tests prove correct registry/default group, nonclickable group, AR/EN, owned/foreign store, Wholesale/Retail, roleless/inactive role, cosmetic hide vs HTTP authority. Existing P193 quotation lifecycle and admin navigation audit remain authoritative.
- Required exact-head gates: Repository Policy, V2 Isolation, backend focused/full, PHP/Pint/PHPStan/security, AR/EN and MySQL/Redis install-upgrade-recovery, final required-ci-gate. Merge Foodex_V2 only after all success. No main, release or production.

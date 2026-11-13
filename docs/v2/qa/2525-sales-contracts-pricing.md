# FOODEX V2 #2525 — P195 Contracts & Pricing: scoped Sales entry point

- **Source**: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P195, Customer & Sales; parent #2503.
- **Target**: `Foodex_V2` ONLY. No `main`, release, tag, deployment or production claim.
- **Placement**: **Reuse → Extend**. Existing `admin.sales-quotations.index` and its authorized Quotations renderer are already discoverable in the Sales business group; related price administration stays in the existing Wholesale Commercial Sales Control and B2B Pricing Workspace. Do not add a second ledger, customer contract authority, sidebar entry, or route.
- **Business meaning**: Sales quotations are offers, **not executed sales contracts**. This bounded increment exposes existing pricing authority but DOES NOT invent, deliver or certify a customer sales contract sign/activate/renew/terminate workflow. P195 must remain OPEN until the original contract lifecycle requirement is independently resolved and accepted.

## Exact route and authority decisions

| Capability | Canonical route | Actor and server gate | Navigation |
| --- | --- | --- | --- |
| Quotations | `admin.sales-quotations.index` / `show` | Authenticated active actor with effective `orders.view` for requested active channel/store; cross-store lookup 404 | Sales Sidebar → existing Quotations; page current-tab link |
| Wholesale pricing policies | `admin.commercial.sales-control` | `catalog.view` effective B2B store scope plus canonical Wholesale principal; destination rechecks auth and scope | Page-level context link, only where eligible |
| Wholesale price tiers | `admin.b2b.pricing-approvals` | `b2b.pricing.view` global and effective B2B store grant, canonical principal, `b2b.pricing_tiers_enabled`; destination rechecks auth, scope and feature mode | Page-level context link, only where enabled |
| Customer sales contract execution | No canonical approved destination established by this increment | **NOT IMPLEMENTED / NOT CERTIFIED** | No dead link or contract-state controls |

Navigation projection uses the same effective `OperationalTenantScope::allowedStoreIds` and pre-existing pricing-mode gate as the destination. It never grants a permission and it never calls the mutating `WholesalePrincipal::storeId()` when rendering Sales quotations. The source of price/tax/discount calculation remains `CommerceQuoteService`; quote create/issue/cancel behavior and audit remain unchanged.

## Experience contract

- **Domain**: Customer & Sales; **parent**: existing Quotations page; **navigation_type**: `PAGE_TAB` / contextual navigation; **route**: `admin.sales-quotations.index`; **fallback**: same store quotations and existing Orders link.
- **Desktop/mobile web**: shared toolbar, tabs wrap responsively, 44px targets, focus-visible outlines; no duplicate header/Sidebar.
- **AR/EN RTL/LTR**: labels in both `backend/lang/*/sales_quotations.php`; `dir` and existing mobile/table styling reused. Explicitly distinguish nonbinding quotations from executed contracts.
- **States**: enabled Wholesale shows authorized pricing destinations, disabled tiers hides just price tiers, Retail shows quotations only; inactive/unauthorized actor receives HTTP denial and no price links.

## Regression and cross-surface classification

Dedicated `V2SalesContractsPricingNavigationTest` checks B2B enabled/disabled, EN/AR rendering, Retail no Wholesale links, foreign-store 404, no orders/quotes created on read, and permission 403. Existing `V2SalesQuotationWorkflowTest` covers audited mutations, price snapshots, maker access, idempotence, stale pricing/stock, 404 and inactive users. Required CI exact-head and real browser UAT remain to be evidenced; do not infer PASS from writing tests.

| Surface | Impact | Evidence / reason |
| --- | --- | --- |
| Backend / API | Internal Dashboard controller adds view-model URLs only | No `routes/api.php`, API schema, database migration, actor permission or mutation changed |
| Dashboard | **Affected** | Existing Quotations page gets AR/EN contextual pricing navigation |
| Customer Flutter | Not affected by this bounded diff | No Customer API, pricing algorithm or Flutter source changes; shared `CommerceQuoteService` untouched |
| Driver Flutter | Not affected | No dispatch, driver route/status, API, notification or app source changes; B2C=Driver only |
| Van Flutter | Not affected | No B2B dispatch, assignments, van route, API or app source changes; B2B=Van only |

## Non-duplication / outstanding acceptance

- Do not modify `AdminNavigation.php` owned by concurrent #2527 / PR #3321 or `backend/routes/web.php` owned by #2532 / PR #3324. P229 #2559 owns profitability rule work and is not duplicated.
- Any required **customer contracts** persistence, approval, versioning and contract-specific maker/checker is a separate, unverified source requirement. Do not represent quotes as binding contracts.
- This PR is a bounded, testable contribution rather than proof of complete P195 acceptance. Keep #2525 open unless an independent requirement audit establishes closure with green exact-head CI and security/UI evidence.

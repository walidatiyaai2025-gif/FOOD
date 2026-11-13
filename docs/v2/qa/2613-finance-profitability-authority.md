# FOODEX V2 #2613 — Finance Profitability / الربحية (P283)

**Source:** FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, P283, parent #2503.
**Integration:** Foodex_V2 ONLY. No production, main, releases, tags, or deployments.

## Canonical business placement and scope

- **Domain:** Finance & Treasury; profit centers and ledger-derived profit and loss.
- **Entity:** enterprise Finance Profit Center, fiscal year and period, financial statement. This is **P283 Finance profitability**, not P315 commercial/BI profitability.
- **Role:** active Finance reader/operator with effective store-scoped finance.view, healthy and version-certified Finance module, legal-entity finance.foundation.read and finance.gl.read entitlements. Mutations separately require finance.manage and finance.foundation.manage.
- **User goal:** see actual profit centers and the ledger-based P&L statement, with the selected authorized store/legal entity, localized labels and no arbitrary store switching.
- **Workflow:** Finance business group → existing Finance Workbench route admin.erp.finance.index → horizontal tabs profit_centers and statements (statement=profit_and_loss, period_id from the existing fiscal-period lookup). The overview already links to that same statement and shows profitability KPIs. Existing records and fiscal periods are business-managed Master Data, not raw ID user input.
- **Placement:** REUSE existing responsive workbench/tabs and existing permitted route. A duplicate sidebar/page/BI read model would fragment permission/entitlement/finance ledger authority. Entry and fallback are the canonical Finance Workbench, not a new route.
- **Presentation:** AR/RTL and EN/LTR use current finance_workbench translation keys and the existing responsive Dashboard tables and forms. No new UI markup, CSS or navigation. Real 320/360/390/412/430/768/1024/1280 viewport, accessibility, browser/device acceptance is deferred to #2503/#2750, not claimed in this source-only PR.

## New executable source acceptance

backend/tests/Feature/V2FinanceProfitabilityPageAuthorityTest.php verifies:

1. Creating an actual profit center through the canonical FinancePlanningService, and real fiscal year/period via FinanceFoundationService; authorized HTTP renders the exact named profit-center record and the real profit_and_loss financial-statement view for that period in both AR/RTL and EN/LTR.
2. A forged different Retail store_id receives 404 on both profit center and P&L URLs; authorized store continues to work.
3. Finance module disabled, missing foundation read capability, or ENABLED but uncertified module fail-closed on direct HTTP; only an operationally ready, entitled actor sees the tab.
4. Two actual store roles do not defeat user-level explicit DENY. A finance.manage DENY retains read-only display but makes createProfitCenter fail 403 with no persisted record; finance.view DENY revokes direct HTTP entirely.
5. Inactive/roleless actors cannot access the protected profitability view.

Existing canonical Finance GL/SoD accounting tests own journal amounts and posting behavior. P283 is **not** P315 BI report execution: old PR #3228 for #2645 is CLOSED UNMERGED and owns ReportBuilderExecutor commercial orders measure; not recreated here. #3269 / DRAFT PR #3276 owns Finance controller and Sidebar legal-entity/store entitlement parity; no shared file touched. #2616 P286 Customer AR has separate security-regression blocker #3217; no AR route/ledger touched.

## Authorization, data, and app impact

| Surface | Impact |
| --- | --- |
| Backend | Additive isolated Laravel Feature tests only, no endpoint, route, schema, permission, finance balance, account policy, module registry, or security behavior modifications. |
| Dashboard | Existing Profit Centers and Profit & Loss tabs and published Finance UI reused; no markup or separate Sidebar item. |
| Customer | NOT AFFECTED: no Flutter, commerce/pricing/order/invoice or public API contract change. |
| Driver | NOT AFFECTED: B2C Driver-only fulfillment and wallet/settlement contracts unchanged. |
| Van | NOT AFFECTED: B2B Van-only assignment, visit, routing, inventory contracts unchanged. |

Test-only synthetic Retail stores, Finance roles, fiscal periods and profit centers live in disposable test DB. No production financial records or credentials modified. SoD remains unchanged.

## Delivery and CI gates

ONE issue, ONE owned branch test/2613-v2-finance-profitability-authority, ONE PR to Foodex_V2. Before merge: exact FINAL head Repository Policy SUCCESS, FOODEX V2 Isolation SUCCESS and FOODEX Required CI terminal gate SUCCESS with Laravel/PHP/Pint/PHPStan/security/OpenAPI, AR/EN and real MySQL/Redis fresh-install/upgrade/recovery. Never infer green from partial, prior-head or unrelated run. Close #2613 only after verified merge plus fully supported P283 source acceptance; manual V2 browser/device UAT remains deferred to final master #2503.

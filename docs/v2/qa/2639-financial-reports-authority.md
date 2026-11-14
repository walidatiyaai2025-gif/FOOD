# V2 #2639 — P309 financial reporting authority and placement

- Parent: #2503. Source: native document Part 2 section 4, paragraph P298; legacy key P309 in docs/v2/V2_SIDEBAR_ISSUE_MATRIX.json.
- Isolation: Foodex_V2 only. No main, release, tag, staging or production deployment.

## Canonical placement

Finance and Treasury / financial reporting for selected legal entity / authorized Finance actor / frequently used period report. Data explorer / Analytics & BI tab composition. **Reuse** the established Finance Workbench GET /admin/erp/finance?tab=reports&store_id=AUTHORIZED_STORE, with sibling tab=statements. Do not introduce a second financial report route or an extra Sidebar destination.

Placement metadata: domain=Finance & Treasury; entity=finance reporting; role=effective finance.view-granted actor; user_goal=review scoped financial period data; workflow_type=read-only reporting; frequency=repeated; data_density=period selection and bounded summaries; existing_parent=Finance Workbench; placement_type=tab; pattern=Analytics/BI; navigation_impact=none; desktop/tablet/mobile=existing responsive Finance Workbench; rtl_ltr=existing AR/EN Blade; permissions=finance.view plus scoped finance.foundation.read for reports, finance.gl.read for statements; states=enabled, unavailable, explicit denial and foreign store.

Canonical source inspection:
- FinanceWorkbenchController selects only authorized store/legal entity contexts, then checks finance module enablement and the access matrix before accessing the selected tab.
- Finance reports tab requires finance.foundation.read entitlement and finance.view effective authorization. Financial statements tab requires finance.gl.read. Module disabled, rejected store context or explicit permission DENY must not expose either report.
- Existing Finance Analytics reporting offers cash flow, budget against actual, VAT/tax, consolidated, variance exceptions, fixed assets and close status. Financial statements offer trial balance, P&L and balance sheet.
- General Management ReportController at /admin/reports uses reports.view and offers operational sales/order/product reports; it is not a replacement for financial ledger reporting.

## Executable coverage delivered

backend/tests/Feature/V2FinancialReportsSidebarAuthorityTest.php adds real authenticated Laravel HTTP tests for:
1. Disabled Finance module => 403.
2. Authorized Retail actor opens actual Finance reports and statements tabs in AR/RTL and EN/LTR; a distinct Retail store is 404.
3. GL read capability alone does not grant the Finance financial-reports tab; granting foundation read enables it.
4. Store-scoped explicit finance.view DENY revokes report access without deleting the role assignment.
5. A real posted Retail AR document in the actor's store (12 KWD) and a posted document of a sibling Retail store in the SAME legal entity (900 KWD), both overdue, are tested through the actual Finance overview. Own-store posted settlement 4 KWD leaves 8 KWD outstanding/overdue/aging; own-store *draft* settlement 7 KWD does not reduce exposure; sibling-store posted settlement 250 KWD must not affect the actor's store. HTTP GET must not modify either AR documents or allocations.

Reuses current production routes and shared UI. **Production correction:** `FinanceAnalyticsService::overview` now scopes both AR outstanding/overdue and AR aging to the selected Retail store even when another Retail store shares its legal entity. Wholesale retains the existing legal-entity-wide view. This does not change shared navigation, Finance controllers, posting APIs, tables, allocations, authorization grants or the Customer/Driver/Van apps. Previously, overview AR metrics and aging aggregated sibling Retail AR due to a `storeScoped=false` argument. This is a report-data-scope correction, not a new Finance entitlement. #2858 / PR #3065 owns workflow-readiness changes and existing common tests; do not overwrite their code.

## Posted-ledger provenance decision — BLOCKED (not an implementation claim)

- **Current schema evidence:** `enterprise_finance_journals`, `enterprise_finance_journal_lines` and `enterprise_finance_ledger_entries` persist organization/company/legal-entity/fiscal-period data, but no canonical `store_id` attribution for posted GL lines. `FinanceAnalyticsService::profitAndLossSummary`, `profitAndLossTrend`, `budgetSummary` and several `report()` calculations aggregate at legal-entity scope; `FinancialStatementService` trial balance, P&L and balance sheet likewise use legal-entity scope. A Store Admin with Retail store role plus inherited legal-entity Finance entitlement must **not** be presumed to have corporate GL authority simply by sharing that entity with other stores.
- **Unresolved business authority decision:** (A) explicitly authorize and **label** a legal-entity report only for actors with an independently verified legal-entity-level Finance role/scope; suppress/deny unauthorised store-level GL metrics and exports, or (B) implement canonical immutable posting-time store provenance in GL, with vetted allocation rules, historical unknown/unaligned ledger treatment, reconciliation and store-scoped read models. Option B must not retroactively assign ownership to arbitrary stores.
- **Implemented fail-closed consolidation guard:** `FinanceAnalyticsService::consolidatedLines()` rejects `ResolvedTenantContext::isRetail()` with HTTP 403 before aggregating company/legal-entity posted entries, even for a Retail store actor with `finance.approve` and legal-entity `finance.foundation.read` entitlement. Laravel HTTP regression `test_retail_store_finance_approver_cannot_open_company_wide_consolidation` exercises sibling Retail stores sharing one legal entity. **This is not a blanket solution:** other GL-derived Retail reports, P&L/period trends/budgets and financial statements still require owner-approved scope/provenance and full coverage. Current canonical source forbids Retail managers from escaping their store scope.
- **Non-negotiable regression proof before merge:** at least two Retail stores sharing one entity and a distinct-company negative case; genuine posted journal entries from both stores using an approved provenance model; positive legal-entity Finance reader and negative Retail-only roles; scoped periods and reports/financial statements/exports; maker/checker SoD; read-only GET; bilingual content, RTL/LTR and keyboard/responsive accessibility. No synthetic attestation replaces validated data origin.
- **Peer boundaries:** `FinanceWorkbenchController` and `FinancialStatementService` plus journal posting/source-mapping have other functional dependencies. Do not rewrite those from a test-only PR before the source owner approves the chosen policy and the owning worker confirms no file overlap.
- **CI dependency:** #3160 / PR #3171 remains blocked on an independent qualified `APPROVED` review of its immutable performance source anchor; a `COMMENTED` author review and a green historical run cannot authorize bypass or merge.

## Individual application impact

| Surface | Classification | Evidence |
| --- | --- | --- |
| Backend/API | Existing admin HTTP overview response now omits unauthorized sibling Retail AR totals; no public API/Flutter contract change | Five authenticated positive/negative tests, including same-entity cross-store ledger fixture |
| Dashboard | Existing Finance workbench, tabs, localization and responsive behavior reused | New real HTTP assertions; browser/a11y runtime still requires final V2 UX gate #2750 |
| Customer | No Customer API, pricing, identity or Flutter changes | No contract delta; existing B2B ordering still VAN only |
| Driver | No Driver app, dispatch, collection or API change | B2C DRIVER-only fulfillment preserved |
| Van | No Van app, visits, route, stock or API change | B2B VAN-only fulfillment preserved |

## Remaining P309 source acceptance — keep issue OPEN

This PR is scoped, automated authorization evidence, **not full financial-report implementation acceptance**. Still verify posted-period calculations beyond the AR overview store-scope negative case, multi-period and multi-company reconciliation, totals/exclusions, report/export boundaries, user role combinations and readiness after #2858, legal-entity/channel isolation, UI discoverability and WCAG/mobile at 320/360/390/412/430/768/1024 widths, keyboard navigation and actual AR/EN rendering. If those checks expose an implementation defect, resolve it through the canonical issue scope and coordination rather than claiming compliance from test-only evidence.

After source-complete exact-head CI validation and Foodex_V2 integration, manual owner/device/staging acceptance may be recorded as MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL under #2503. Do not close until missing source requirements are evidenced. No local toolchain/Composer/Pint test execution claimed from this connector session; GitHub Required CI must provide independent evidence.

## CI and peer-owner boundary

- Original issue branch and PR are preserved; the 2026-11-13 continuation restored the prior two commits without discarding their history and layered them on the isolated `Foodex_V2` tree.
- Modified only the original issue test, this issue QA note and `FinanceAnalyticsService.php`; no touch to the active #2633 AR service/Finance Workbench/routes or #2621 navigation files.
- Required CI **must rerun on the final exact head**; do not treat prior historical green run #43059220482 as approval of this new report-scope code. The independent immutable Enterprise Performance baseline source #3160 must be reviewed and repaired by its owning lane, never bypassed here.
- PR #3212 must remain open and unmerged until all required child jobs, report correctness/reconciliation, and source acceptance are validated. No main/deploy/release.

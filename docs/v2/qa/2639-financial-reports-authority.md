# V2 #2639 — P309 financial reporting authority and placement

- Parent: #2503. Source: native document Part 2 section 4, paragraph P298; legacy key P309 in docs/v2/V2_SIDEBAR_ISSUE_MATRIX.json.
- Isolation: Foodex_V2 only. No main, release, tag, staging or production deployment.

## Canonical placement

Finance and Treasury / financial reporting for selected legal entity / authorized Finance actor / frequently used period report. Data explorer / Analytics & BI tab composition. **Reuse** the established Finance Workbench GET /admin/erp/finance?tab=reports&store_id=AUTHORIZED_STORE, with sibling tab=statements. Do not introduce a second financial report route or an extra Sidebar destination.

Placement metadata: domain=Finance & Treasury; entity=finance reporting; role=effective finance.view-granted actor; user_goal=review scoped financial period data; workflow_type=read-only reporting; frequency=repeated; data_density=period selection and bounded summaries; existing_parent=Finance Workbench; placement_type=tab; pattern=Analytics/BI; navigation_impact=none; desktop/tablet/mobile=existing responsive Finance Workbench; rtl_ltr=existing AR/EN Blade; permissions=finance.view plus scoped finance.foundation.read for reports, finance.gl.read for statements; states=enabled, unavailable, explicit denial and foreign store.

Canonical source inspection:
- FinanceWorkbenchController selects only authorized store/legal entity contexts, then checks finance module enablement and the access matrix before accessing the selected tab.
- Financial reports require a **Wholesale** legal-entity context, effective finance.view, active finance.foundation.read entitlement, and a ready Finance module. Financial statements additionally require finance.gl.read. All Retail contexts are server-denied for legal-entity reports/GL statements, and the Dashboard tabs are disabled accordingly (no false enabled-but-unready link). Module disabled, rejected store context or explicit permission DENY still deny access.
- Existing Finance Analytics reporting offers cash flow, budget against actual, VAT/tax, consolidated, variance exceptions, fixed assets and close status. Financial statements offer trial balance, P&L and balance sheet.
- General Management ReportController at /admin/reports uses reports.view and offers operational sales/order/product reports; it is not a replacement for financial ledger reporting.

## Executable coverage delivered

backend/tests/Feature/V2FinancialReportsSidebarAuthorityTest.php adds real authenticated Laravel HTTP tests for:
1. Disabled Finance module => 403.
2. Authorized Retail actor opens its real Finance **overview** in AR/RTL and EN/LTR; corporate Reports/Statements tabs are disabled and directly 403; a foreign Retail store is 404.
3. GL read capability alone does not grant corporate Financial Reports; adding foundation read enables Retail AR/AP overview only, not legal-entity reports.
4. Store-scoped explicit finance.view DENY revokes the Retail overview without deleting its role assignment.
5. A real posted Retail AR document in the actor's store (12 KWD) and a posted document of a sibling Retail store in the SAME legal entity (900 KWD), both overdue, are tested through the actual Finance overview. Own-store posted settlement 4 KWD leaves 8 KWD outstanding/overdue/aging; own-store *draft* settlement 7 KWD does not reduce exposure; sibling-store posted settlement 250 KWD must not affect the actor's store. HTTP GET must not modify either AR documents or allocations.

**Production corrections:** `FinanceAnalyticsService::overview` scopes Retail AR outstanding, overdue and aging to the selected store even when siblings share one legal entity. It skips querying/returning GL revenue, expense, P&L trend, budgets, fiscal-close, journal and tax figures in Retail mode; AP remains store-scoped. Authorized Wholesale retains canonical company/legal-entity reporting. `FinanceAnalyticsService::report` and `FinancialStatementService` reject Retail access to company-wide posted data with 403. FinanceWorkbench's server access matrix projects the same legal-entity-only decision to the Reports/Statements tabs; its existing overview hides corporate KPI cards and P&L panels for Retail and displays localized store-only AR/AP notice. No changes to posted ledgers, migrations, allocations, public mobile APIs or Customer/Driver/Van contracts. #2858 / PR #3065 owns broader ERP workflow readiness.

## Posted-ledger provenance decision — fail-closed corporate-only for current V2 P309

- **Current schema evidence:** `enterprise_finance_journals`, `enterprise_finance_journal_lines` and `enterprise_finance_ledger_entries` persist organization/company/legal-entity/fiscal-period data, but no canonical `store_id` attribution for posted GL lines. `FinanceAnalyticsService::profitAndLossSummary`, `profitAndLossTrend`, `budgetSummary` and several `report()` calculations aggregate at legal-entity scope; `FinancialStatementService` trial balance, P&L and balance sheet likewise use legal-entity scope. A Store Admin with Retail store role plus inherited legal-entity Finance entitlement must **not** be presumed to have corporate GL authority simply by sharing that entity with other stores.
- **Chosen non-disclosure rule for P309:** authorized legal-entity Finance reporting is available in Wholesale scope only, with existing Finance permission/entitlement boundaries. Retail access is limited to its store-attributable AR/AP and store-level pending approvals; corporate GL reports and statements are disabled in the UI and denied by services. A future Retail *store-level* GL report requires (B) canonical immutable posting-time store provenance, approved allocation semantics and historical unknown/unaligned ledger treatment; it must never infer store ownership from a shared legal entity. This is a fail-closed restriction, **not** a claim of implemented store P&L.
- **Implemented comprehensive P309 report guards:** all seven legal-entity report types are server-denied to Retail actors, as are trial balance, P&L and balance sheet; `consolidatedLines()` also has an explicit defense-in-depth Retail guard. The real HTTP test checks ten report shapes in a same-legal-entity sibling-store fixture even when a Retail role holds finance.approve and finance.gl.read; a separate Wholesale SUPER_ADMIN corporate-context test positively exercises Reports and Statements in both locales. Retail overview source data and DOM contain only store AR/AP and pending approval metrics, not company GL KPI cards.
- **Validation and open acceptance:** same-entity two-Retail-store negative AR/GL/report cases, positive Wholesale legal-entity report/statement HTTP cases, period selection, read-only GET and bilingual RTL/LTR all require exact-HEAD CI. Full posted-journal/multi-company financial reconciliations, maker/checker authority across export delegation, WCAG/device/runtime coverage and any future store-attributed GL still need end-to-end proof or separately scoped gate; do not mislabel manually unverified behavior as certified.
- **Peer coordination:** #2633 / PR #3309 remains OPEN/DRAFT and owns AR settlement hunks in `FinanceWorkbenchController`, shared Finance Blade and AR/EN finance_workbench translations. The original #2639 PR changes only **different** reporting/access matrix, overview Blade hunks and adds **separate** `finance_p309` locale files; coordination comment on #2633 documents the split. Revalidate before merge and reconcile any later conflict without overwriting AR source-owner code or taking #2633 ownership. No posting/migrations altered.
- **CI dependency:** #3160 / PR #3171 remains blocked on an independent qualified `APPROVED` review of its immutable performance source anchor; a `COMMENTED` author review and a green historical run cannot authorize bypass or merge.

## Individual application impact

| Surface | Classification | Evidence |
| --- | --- | --- |
| Backend/API | Store-scoped Retail AR/AP overview; 403 on all unproven Retail legal-entity financial reports/GL statements; existing company reports for authorized Wholesale | Authenticated negative + positive tests and original source-service restrictions, no public/mobile API change |
| Dashboard | Shared Finance Workbench now hides company GL KPIs/charts, Reports/Statements links for Retail; AR/EN scope notice; corporate tabs retained for authorized Wholesale | Actual Blade/HTTP assertions, RTL/LTR and responsive existing CSS; browser/device WCAG acceptance remains under #2750 |
| Customer | No Customer API, pricing, identity or Flutter changes | No contract delta; existing B2B ordering still VAN only |
| Driver | No Driver app, dispatch, collection or API change | B2C DRIVER-only fulfillment preserved |
| Van | No Van app, visits, route, stock or API change | B2B VAN-only fulfillment preserved |

## Remaining P309 source acceptance — keep issue OPEN

This PR is scoped, automated authorization evidence, **not full financial-report implementation acceptance**. Still verify posted-period calculations beyond the AR overview store-scope negative case, multi-period and multi-company reconciliation, totals/exclusions, report/export boundaries, user role combinations and readiness after #2858, legal-entity/channel isolation, UI discoverability and WCAG/mobile at 320/360/390/412/430/768/1024 widths, keyboard navigation and actual AR/EN rendering. If those checks expose an implementation defect, resolve it through the canonical issue scope and coordination rather than claiming compliance from test-only evidence.

After source-complete exact-head CI validation and Foodex_V2 integration, manual owner/device/staging acceptance may be recorded as MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL under #2503. Do not close until missing source requirements are evidenced. No local toolchain/Composer/Pint test execution claimed from this connector session; GitHub Required CI must provide independent evidence.

## CI and peer-owner boundary

- Original issue branch and PR are preserved; the 2026-11-13 continuation restored the prior two commits without discarding their history and layered them on the isolated `Foodex_V2` tree.
- Original #2639 branch changes AnalyticsService, FinancialStatementService, FinanceWorkbenchController reporting/access-matrix sections, Finance Blade overview section, independent AR/EN `finance_p309` translation files, the original feature test and this QA note. No `AccountsReceivableService`, AR settlement routes, migrations, Customer/Driver/Van Flutter app or shared navigation changes. #2633 touched controller/Blade in **other** hunks; verify integration compatibility before any merge.
- Required CI **must rerun on the final exact head**; do not treat prior historical green run #43059220482 as approval of this new report-scope code. The independent immutable Enterprise Performance baseline source #3160 must be reviewed and repaired by its owning lane, never bypassed here.
- PR #3212 must remain open and unmerged until all required child jobs, report correctness/reconciliation, and source acceptance are validated. No main/deploy/release.

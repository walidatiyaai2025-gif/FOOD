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

Reuses current production routes and shared UI. No changes to shared navigation, Finance controllers, module/service source or authorization policies. #2858 / PR #3065 owns workflow-readiness changes and existing common tests; do not overwrite their code.

## Individual application impact

| Surface | Classification | Evidence |
| --- | --- | --- |
| Backend/API | Laravel regression added; no production API route or response change | Four authenticated negative/positive tests |
| Dashboard | Existing Finance workbench, tabs, localization and responsive behavior reused | New real HTTP assertions; browser/a11y runtime still requires final V2 UX gate #2750 |
| Customer | No Customer API, pricing, identity or Flutter changes | No contract delta; existing B2B ordering still VAN only |
| Driver | No Driver app, dispatch, collection or API change | B2C DRIVER-only fulfillment preserved |
| Van | No Van app, visits, route, stock or API change | B2B VAN-only fulfillment preserved |

## Remaining P309 source acceptance — keep issue OPEN

This PR is scoped, automated authorization evidence, **not full financial-report implementation acceptance**. Still verify posted-period calculations, multi-period and multi-company reconciliation, totals/exclusions, report/export boundaries, user role combinations and readiness after #2858, legal-entity/channel isolation, UI discoverability and WCAG/mobile at 320/360/390/412/430/768/1024 widths, keyboard navigation and actual AR/EN rendering. If those checks expose an implementation defect, resolve it through the canonical issue scope and coordination rather than claiming compliance from test-only evidence.

After source-complete exact-head CI validation and Foodex_V2 integration, manual owner/device/staging acceptance may be recorded as MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL under #2503. Do not close until missing source requirements are evidenced. No local toolchain/Composer/Pint test execution claimed from this connector session; GitHub Required CI must provide independent evidence.

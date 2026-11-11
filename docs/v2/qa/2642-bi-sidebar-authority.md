# V2 P312 — BI Sidebar authority and readiness

Issue: #2642; parent #2503. Source requirement: P312 (Reports & BI). Target integration branch: `Foodex_V2` only.

## Placement and authorized journey
- **Reuse** the existing Reports & BI / Analytics business group in `AdminNavigation` and the canonical `/admin/erp/bi` workbench. No new screen, Sidebar group, route, database field or technical-input form.
- Domain Reports & BI; entity enterprise reporting; role platform-authorized reporter; user goal run/view permitted BI analyses and schedules; pattern existing authorized report workbench; route `admin.erp.bi`; navigation key `erp_bi`; navigation type `SIDEBAR`; entry from Analytics; permission `reports.view`, with entitlement `enterprise_bi` and certified operational readiness.
- Desktop/mobile/RTL/LTR reuse the untouched shared Dashboard shell and BI page. Navigation and route rendering are checked for Arabic/English; no UI composition or styles are changed.

## Source gap and resolution
Canonical navigation already suppresses unready BI and sends platform-owner diagnostics to the module diagnostic route. Previously the BI GET controller checked role/permission but did not enforce module operational certification, and the BI schedule POST/PATCH endpoints checked reporting permission and report entitlement but not current certified readiness. Consequently a directly typed route could bypass the hidden-navigation readiness gate. `EnterpriseBiReadinessGate` now applies the same `ModuleOperationalReadiness::status` decision to the BI GET and schedule state-changing endpoints, including fail-closed on a missing/invalid module or health failure; it never treats hiding a Sidebar item as security.

No production certification is granted, no entitlement is created, no report permissions are broadened, and no dataset, ledger, export, schedule or service credentials are modified by the patch.

## Automated acceptance
- `backend/tests/Feature/V2BiSidebarAuthorityTest.php`: certified/entitled module appears exactly once under Analytics and its canonical page returns 200 in AR/RTL and EN/LTR.
- Disabled and enabled-but-uncertified BI are not linked and all BI GET, schedule POST and schedule PATCH attempts return HTTP 403 before mutation; schedule table remains unchanged.
- Retail-only and inactive identities cannot use BI directly or acquire the BI Sidebar entry.
- Existing `EnterpriseBiWorkbenchTest`, `V2ErpBusinessDomainNavigationTest`, `V2SidebarAuthorityMatrixTest` and adjacent reporting tests remain required regression checks. Other module/read-model and store-scoped reporting authority lives in `ReportAccessResolver` and the existing report/Business Analyst authorization tests, and is not replaced by this gate.

## Mobile impact classification
- Customer: **no application source or public mobile API schema change**; web Dashboard only, no Customer order/credit/pricing contract modified.
- Driver: **no application source or public mobile API schema change**; no dispatch, status, wallet or proof endpoint modified.
- Van: **no application source or public mobile API schema change**; no route, inventory, visit or settlement endpoint modified.
- A separate mobile Issue is not warranted because the diff affects only three internal web-dashboard entry points and backend feature tests.

## Evidence and restrictions
PR exact-head CI and final integrated SHA must be recorded on #2642 before claiming closure. No `main` merge, release, tag or production deployment. Manual browser/device acceptance belongs to the final V2 gate per owner decision on #2503, not a claim of completed manual UAT.

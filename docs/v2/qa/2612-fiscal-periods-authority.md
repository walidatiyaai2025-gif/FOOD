# V2 #2612 — Fiscal Periods (source P282)

## Canonical implementation / placement

- **Domain / entity:** ERP Finance and Treasury / `enterprise_fiscal_years`, `enterprise_fiscal_periods`.
- **Role / user goal:** an authorized accountant views fiscal periods; `finance.manage` operators create, close, and reopen records, with the proper legal-entity entitlement.
- **Reuse decision:** Existing `admin.erp.finance.index?tab=periods&store_id=<scoped-store>` in `FinanceWorkbenchController@index`, rendered by `admin/finance-workbench.blade.php`. The existing Finance Workbench navigation/tab is the entry point; do not introduce a new sidebar node, standalone fiscal calendar or second accounting ledger.
- **Placement / pattern:** existing Finance Workbench **page tab** with forms and period grid. Desktop, tablet, mobile and RTL/LTR reuse the existing responsive and localized finance components. Existing tab and back path: Finance Workbench / Overview.
- **Read contract:** `finance.view` + `finance.foundation.read` on current enterprise legal entity + active/certified Finance module; `GET admin.erp.finance.index?tab=periods` verifies the same authority.
- **Write contract:** `finance.manage` + `finance.foundation.manage` on the selected enterprise legal entity + active Finance module; POST create fiscal year / period and PATCH period state reach `FinanceFoundationService` and its server-authorized `EnterpriseDataScope`. Form visibility is **not** an authorization grant.
- **Record isolation:** stored `organization_id`, `company_id`, `legal_entity_id` are derived server-side; foreign IDs fail at scoped model queries before state mutation.
- **Finance integrity:** period dates must stay within year and must not overlap; closing/reopening respects fiscal-year state and audits transitions. No pre-existing data rewritten.
- **SoD:** period management has no implied `finance.post` or `finance.approve`; posting and approval use separate existing permissions and workflows. A Finance viewer is not elevated by seeing the tab.
- **Disabled/unready:** Finance module, missing legal-entity entitlement, role denial or disabled operator must fail closed; no fallback menu visibility policy.

## Issue-specific automated evidence

`backend/tests/Feature/V2FiscalPeriodsRouteAuthorityTest.php` exercises:
1. Route registration, AR/EN period title, RTL/LTR, read-only tab and direct forbidden year creation for `finance.view` without `finance.manage`.
2. HTTP fiscal-year and fiscal-period creation; actual DB persistence, period visibility, close/reopen via PATCH and audit log for an entitled manager.
3. Two synthetic retail stores with distinct enterprise legal-entity scopes: another store's period is absent from the list, PATCH returns 404 and original record/audit remain unchanged. Forged store context denied.
4. Disabled Finance lifecycle denies both GET and POST with no persistence.

Related canonical tests already cover fiscal overlap, premature closing, fiscal-year state constraints and module disablement: `EnterpriseFinanceFoundationTest` and `FinanceWorkbenchTest`. Existing built-in RBAC and module authority must not be weakened. Explicit per-user overrides/multi-role collision cases remain in the central V2 permission and finance security matrices; this test verifies the P282 route boundary rather than reimplementing authorization.

The fixture uses **synthetic test-only users/stores and future fixed period ranges**, not live accounts or current production financials.

## Cross-app impact classification

- **Customer:** no route, API payload, pricing, order checkout or authentication change in this Issue; no Flutter source modification needed.
- **Driver:** no dispatch, cash collection, wallet or notification contract change; no Flutter source modification needed.
- **Van:** no B2B dispatch, van stock, collections or assignment contract change; no Flutter source modification needed.

These are separate impact determinations because the deliverable consists of a Laravel regression and scope documentation, without modifying a shared app API.

## Release and acceptance boundary

- One Issue / one branch / one PR. Integration target **`Foodex_V2` exclusively**.
- The PR cannot be merged before **all exact-final-head Required CI child jobs**, Repository Policy and V2 Isolation Gate succeed; the final CI links and SHA belong in the Issue completion comment.
- `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` under owner decision #2503; no browser/staging/device UAT claimed.
- Do not modify `main`, publish a release or deploy to production.

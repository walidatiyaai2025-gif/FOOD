# V2 P293 — Cashbox / صندوق page and action authority

Issue: #2623. Single branch: `test/2623-v2-cashbox-scope-authority`. **Integration: Foodex_V2 ONLY**.

## Canonical product placement
- **Domain/entity:** Finance & Treasury → Cashbox master (صندوق/الخزن); role: platform/B2B finance users and assigned Retail Finance users.
- **Intent:** Browse, create and update scoped operating cashboxes. Reuse existing `admin.treasury.master.index?tab=cashboxes` as a horizontal PAGE_TAB inside Treasury Master. There is already a Finance Sidebar entry `treasury_master` for authorized platform users and a Treasury Workbench link to master data in retail journeys.
- **Navigation:** owner `AdminNavigation`, business group `finance`, existing `treasury_master`; `Route::has('admin.treasury.master.index')`; authorized platform Sidebar → tab and retail Treasury master link. No duplicate page or direct-URL-only destination. Back path is the existing Finance Sidebar/ERP Treasury workbench.
- **Authorization:** authoritative `OperationalTenantScope::allowedStoreIds/assertStore` on `finance.view` for GET and `finance.manage` for POST/PATCH. Retail B2C store A/B is isolated, single-scope DENY outranks role grant, and a roleless user receives HTTP 403; no frontend permission grant.
- **Entitlement/readiness:** The Cashbox master records are existing finance reference data; this route is not an enabled Treasury transactional module. `admin.erp.treasury.index` remains independently fail-closed without enabled/certified Treasury module/entitlement (existing `EnterpriseTreasuryWorkbenchTest::test_workbench_fails_closed_while_treasury_module_is_disabled`). No receipt, payment, GL posting, or enabled-but-unready business route was added.
- **UI:** unchanged canonical Treasury Master page and FOODEX shared tokens/header/sidebar, localized `treasury.cashboxes` in AR/RTL and EN/LTR; existing mobile-web breakpoint uses one-column controls below 768px, empty/table/forms and 44px controls; mutation readback returns to the cashboxes tab.
- **Form fields:** Store is an authoritative lookup; cashbox name is legitimate free text; scope/status are Enum/Checkbox; cashbox code is the constrained business reference, not an internal database ID.

## Regression coverage
New `backend/tests/Feature/V2CashboxSidebarAuthorizationContractTest.php` proves:
1. B2B authorized Finance Sidebar → Cashboxes tab, AR and EN direction/label and the existing responsive Treasury Master renderer.
2. B2C store A read vs foreign store B, foreign edit/create HTTP denial, plus successful own-store create/update with database readback and audit record.
3. Read-only `finance.view` has no Create/Edit actions; POST/PATCH are denied and data unchanged.
4. Explicit per-store `finance.view` deny blocks one of two assigned Retail stores while retaining access to the second; roleless 403.

## Customer / Driver / Van impact
- **Customer:** not affected — existing Dashboard master records only; no customer API, pricing or ordering change.
- **Driver:** not affected — no Driver application, wallet, remittance or delivery contract change.
- **Van:** not affected — no Van app, VAN-only B2B fulfillment, collection, or settlement contract change.

Exact-head CI evidence must be verified on the PR before merging. Screenshot evidence is retired by #2472; manual browser/device UAT is deferred to final V2 master #2503, not claimed as completed. No `main` promotion, release, tag or production deployment.

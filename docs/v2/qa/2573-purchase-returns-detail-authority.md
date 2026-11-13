# V2 P243 — Purchase Returns authorized detail journey (#2573)

**Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` P243, Procurement & Inventory. Parent #2503; integration branch `Foodex_V2` only.

## Placement and navigation contract
- **Domain:** Procurement; **entity:** purchase return; **actor:** authorized procurement buyer/manager or role with scoped `procurement.purchase_returns.view`; **goal:** inspect a specific return, supplier, source goods receipt, returned quantities and status before acting.
- **Workflow:** frequent operational read in Procurement. **Existing parent:** ERP Procurement workbench, canonical route `admin.erp.procurement.index`, `tab=returns`. **Placement:** Extend existing data grid with a direct exact-record row link and an inline detail card. **Pattern:** Operational Workbench / Record 360 detail. **Navigation type:** PAGE_TAB under the existing `erp_procurement` procurement business-domain entry; no new Sidebar item or route.
- **Entry/active context/back path:** existing Procurement Sidebar → Returns tab → View the selected row (`return=<id>`) on that same authorized store's tab; the tab and store stay selected and the destination fragment targets the detail section. Filter/search and other workbench tabs remain available.
- **Responsive and accessible:** existing `foodex-admin-layout`, `erp-table-wrap`, shared card/detail grid and link focus/touch tokens are reused. Details appear below the table, not in an expanded page-width column; native link and an `aria-labelledby` section provide focus/discoverability. Desktop/tablet/mobile web use the same server-authorized data and AR/RTL, EN/LTR strings.

## Functional defect and remediation
- Before P243, `EnterpriseProcurementController::index` already resolved an explicitly selected `return` with `PurchaseReturnManager::detail()`, but the Returns grid omitted the View action and the Blade template never rendered `$detail`. Users could see a row but not inspect its canonical return lines, source receipt and status.
- The Returns grid now includes a translated `View` link to `?tab=returns&store_id=<scoped>&return=<exact id>` (existing route, no URL-auth bypass) and a detail card showing return number, translated status, bilingual supplier name, source receipt, returned quantities, human-readable descriptions/item references and unit cost/currency. This is a read-only addition; state-changing Post/Cancel flows retain their original server authorization and inventory SoD behavior.
- No new labels or hard-coded system wording; reused `enterprise_procurement` AR/EN keys and existing `$statusLabel`, with data names chosen by current locale. No new UI input or technical ID entry introduced.

## Authorization and data scope
- Controller refuses disabled Procurement module, missing store/tenant, unknown tab and unavailable `procurement.purchase_returns.view` before rendering.
- `PurchaseReturnManager::detail()` independently applies the module and `EnterpriseScopeAuthorizer` constraints; source Return ID must belong to the selected tenant organization, company and store, otherwise a 404. Foreign store selection gets 403. No unscoped results or cross-store supplier lookup.
- A user's `manage`/`post` permissions do not automatically grant extra read data. The existing Dashboard `can` projections still gate Post/Cancel, and no mutation authorization or release functionality changed.
- Module/readiness/entitlement and Sidebar placement inherited from canonical ERP Procurement; no separate visibility policy was introduced.

## Automated evidence and impact

### MOBILE IMPACT — Customer / Driver / Van

Customer, Driver and Van are each reviewed below for API/runtime and Flutter consequences; no shared app contract is changed by this Dashboard-only, read-only navigation fix.
- `backend/tests/Feature/EnterprisePurchaseReturnFoundationTest.php` gains real HTTP AR/RTL and EN/LTR assertions for the exact record link and rendered detail, plus foreign store 403, foreign return 404 and missing view permission 403. Existing feature tests already verify module-disabled fail-closed, bounded collection, return quantity limits, inventory reservation, idempotent post/cancel and reversal audit evidence.
- **Backend:** only existing workbench Blade and its service-backed HTTP test; no API, migrations or schema changes.
- **Dashboard:** exact-record navigation/detail for Returns with responsive native primitives and bilingual labels. No duplicate routes.
- **Customer:** unaffected — public/customer order APIs, order lifecycle and Flutter Customer unchanged.
- **Driver:** unaffected — B2C DRIVER ONLY dispatch/status/collection APIs and Driver Flutter unchanged.
- **Van:** unaffected — B2B VAN ONLY visits/load/returns or offline replay APIs and Van Flutter unchanged; this is privileged procurement Dashboard read only.
- **Release:** no `main` merge, tag, production deployment, cPanel upgrade or APK publishing authorized.
- **Required CI:** exact-final-HEAD Repository Policy, FOODEX V2 Isolation, FOODEX Required CI (Laravel tests, syntax/Pint/PHPStan, Composer security, OpenAPI, localization, real MySQL/Redis install/upgrade/recovery, terminal `required-ci-gate`).

**Manual browser/device/UAT:** DEFERRED_TO_V2_FINAL (master #2503 / UX #2750), per owner decision 2026-11-10. Automated checks do not constitute production verification.

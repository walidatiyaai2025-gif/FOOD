# V2 #2570 — P240 Purchase Requests (طلبات الشراء) source acceptance

**Parent:** #2503 / Unified RBAC-ERP-Sidebar Discussion (2026-11-09) P240. **Target:** `Foodex_V2` ONLY. **Status:** test/QA integration evidence; not production signoff.

## Existing product location and functional path

- **Business domain/entity:** Procurement & Inventory / Purchase Request.
- **User goal:** view store-scoped purchase requests; create a draft, submit it for independent approval, inspect the exact record, reject/approve, cancel, and progress an approved request to a purchase order.
- **Roles:** `PROCUREMENT_BUYER` (view/manage, not independent approve), `PROCUREMENT_APPROVER` (view/approve, not manage), authorized scoped management actor. Role name alone is never an entitlement.
- **Placement decision:** **REUSE** the established Procurement Workbench `admin.erp.procurement.index` with page tab `?tab=requests&store_id=<authorized>`, reached through existing authorized `erp_procurement` domain entry under Catalog. Navigation type `PAGE_TAB` inside a business `SIDEBAR` domain; no fabricated P240 route, duplicate link, drawer, grant or schema.
- **Actual routes:** `admin.erp.procurement.index` (`tab=requests`), `admin.erp.procurement.requests.store`, `requests.submit`, `requests.decision`, `requests.cancel`. Actual HTTP controller uses per-selected-store authorization `procurement.purchase_requests.view`, and the `PurchaseRequestManager` provides separate manage/approve and maker/checker state-transition authorization. Existing `EnterpriseProcurementWorkbenchTest` already proves draft → submit → independently approve → PO; do not duplicate these lifecycle implementation paths.
- **Navigation authority:** existing `AdminNavigation` module-gated `erp_procurement` points to the same Procurement Workbench. Built-in Procurement Buyer/Approver personas have the corresponding Suppliers entry permission and the Requests page permission; `AdminNavigation` uses exact-version readiness and enterprise entitlements before rendering. Existing finance worker #3269 owns `AdminNavigation.php`, so this P240 PR does NOT touch that shared source. A user with a nonstandard request-only permission and no other Procurement entry is outside this certified persona, and should be evaluated in the broader #2758 effective-visibility parity gate rather than falsely represented as tested.
- **Module readiness:** `ModuleRegistry` enabled, platform/store entitlement, exact certified version, real controller `EnsureErpOperationalReadiness` and selected store scope. Disabled/uncertified module must fail closed.
- **AR/EN & responsive:** existing Blade translated `enterprise_procurement.tabs.requests` (AR: طلبات الشراء; EN: Purchase Requests), RTL/LTR, responsive toolbar, accessible tabs and form elements are reused without modification. Browser 320px/mobile/web keyboard/screen-reader UAT remains on #2503/#2750.

## New, nonduplicative P240 executable regression

`backend/tests/Feature/V2PurchaseRequestsTabAuthorityTest.php` asserts:

1. Built-in independent Buyer/Approver roles have distinct `view/manage/approve` abilities, both discover the existing canonical Procurement sidebar entry, and each can load the Requests tab as actual HTTP 200 with correct AR/EN/RTL/LTR; route registry includes real request-specific state mutations.
2. Before Procurement is enabled/certified/entitled the link stays hidden and HTTP rejects it. After enabling it, a B2C Driver or roleless actor is still denied by HTTP and navigation.
3. B2B Buyer cannot select an unrelated Retail store's purchase requests. An explicit `procurement.purchase_requests.view` DENY masks the Requests tab and blocks direct HTTP 403 even when the same buyer still legitimately retains Suppliers access. No purchase requests are written by that negative test.

No additional runtime changes, no redundant P239 Suppliers/PO P241 implementation, and no second UI authorizer. Existing independent #2712 persona and source-level procurement lifecycle tests remain authoritative for action/SoD.

## Per-surface review

| Surface | Impact |
| --- | --- |
| Backend | Adds 3 dedicated Laravel feature regression tests for the existing request workbench; no public API, data model, role, migration or controller changed |
| Dashboard | Existing Procurement route/tab, module readiness and AR/EN components reused. No UI markup changes |
| Customer | No app/REST, login, customer buying/price, notification or checkout change |
| Driver | No app/REST, Retail B2C Driver-only dispatch/wallet/collection change; Driver denied Procurement |
| Van | No app/REST, Wholesale B2B Van-only assignment/visit/stock/dispatch change |

**Required gates:** one P240 issue-scoped PR `base=Foodex_V2`, exact final HEAD Repository Policy + V2 Isolation + Required CI, Laravel tests, PHP/Pint/PHPStan/security/OpenAPI, localization AR/EN, real MySQL/Redis install/upgrade/recovery, terminal required-ci-gate. Fix any failure in same PR without weakening existing protections. Release only after verified merge and source acceptance.

**MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL** per owner override #2503, with UX #2750. Does not claim device UAT, production deployment, signed Setup/Update ZIP, main promotion or APK release.

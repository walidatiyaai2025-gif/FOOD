# V2 #2658 — P328 Restaurant analytics authority / navigation proof

- **Source:** FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, P328, Applications & Restaurant.
- **Integration:** `Foodex_V2` only; no `main`, production, release tag or deployment.
- **Actor / tenant:** B2C restaurant operator with store role and `smart_ordering.analytics.view`; SUPER_ADMIN is permitted for an explicit support-access context only.
- **Placement decision:** **Reuse** the existing Restaurant SaaS workspace, shared horizontal Analytics tab and Restaurant Management launcher. No new route, Sidebar item, second permission layer or page.
- **Entry:** canonical Restaurant SaaS entry `admin.smart-ordering.index` → workspace tab / management launcher → `admin.smart-ordering.analytics.index`; accessible back to workspace, same desktop/mobile browser navigation, locale-aware AR/RTL and EN/LTR labels.
- **Authoritative controller:** `SmartOrderingAnalyticsController::context()` → `RestaurantSaasStoreAccess::allowedStoreIds()`; the controller still owns all enforcement.

## Fixed discrepancy

Previously the shared workspace tab used the broad `AdminNavigation::hasPermissionInScope(..., smart_ordering.analytics.view)` result, which could display a link for the **currently selected** store even if its Restaurant profile, `STORE:smart_ordering` master entitlement, or effective store-level permission denied the analytics destination. That produced a dead 403/404 link, especially in multi-store contexts.

The tab and launcher now use `RestaurantSaasStoreAccess::canNavigateToStore()` — a **read-only, non-throwing display projection** based on canonical `OperationalTenantScope::allowedStoreIds`, effective per-store permission (including DENY), Restaurant business profile, `SmartOrderingEnterpriseEntitlementBridge::storeEnabled`, and explicit Super Admin support context. The route controller continues to independently enforce every condition; UI visibility is never an authorization grant.

## Scope / navigation / data classifications

- Domain: Restaurant SaaS → Applications & Restaurant; role: B2C Store Admin or explicitly supported Super Admin.
- Existing parent: Restaurant Settings workspace; navigation type: **PAGE_TAB** and existing management launcher contextual link; same canonical analytics URL.
- No new database fields, migrations, raw IDs or runtime API. Existing filters, analytical views, CSV export and saved data are unchanged.
- New UI text: none; existing `smart_ordering.analytics.nav_title` and workspace translations serve AR/EN. No structural/CSS or action change; existing `aria-current`, focusable tab, responsive launcher retained.

## Regression acceptance

`backend/tests/Feature/V2RestaurantAnalyticsSidebarAuthorityTest.php` checks:

1. Retail store admin with enabled Restaurant SaaS + analytics permission reaches the real HTTP analytics page in **EN/LTR** and **AR/RTL**, with shared tab and management-launcher entry.
2. Foreign unassigned store access is HTTP 404 and never exposed as an analytics tab/link.
3. Assigned Retail store with disabled `STORE:smart_ordering` entitlement returns HTTP 404 for analytics and no link, while another enabled assigned store retains its tab.
4. A user with multiple assigned Retail stores and a scoped `deny` override for analytics cannot enter or see the denied store's tab, but can still reach the separately authorized store.
5. Super Admin has no implicit support entry; without explicit support HTTP 403, with explicit support the canonical route is reachable.
6. All authorization remains server-side; no roles, permission grants, data or entitlement writes in product code.

## Mobile / security impact matrix

| Surface | Classification | Evidence / follow-up |
| --- | --- | --- |
| Dashboard web (desktop/mobile) | **Affected: navigation visibility only** | Reuses existing responsive shared tabs and AR/EN strings; scoped Laravel page/nav tests |
| Customer Flutter | **Not affected** | No API, auth, route, menu or customer-facing payload changed |
| Driver Flutter | **Not affected** | No dispatch, driver API, login or notifications changed |
| Van Flutter | **Not affected** | No wholesale VAN routing, visit API, login or notifications changed |

No new mobile issue is necessary for this Dashboard-only presentation correction. Tenant B2B/VAN-only and B2C/DRIVER-only dispatch remain unchanged. No financial write or SoD transitions are involved.

## CI / release status

Exact-head required checks (Laravel, AR/EN, Repository Policy, FOODEX V2 Isolation, Required CI) must be verified on the PR before merge. Running tests in GitHub Actions is not equivalent to live UAT or production validation. Manual staging/UAT and original source acceptance are explicitly deferred to the V2 program's final consolidated gate; this PR must not claim either.

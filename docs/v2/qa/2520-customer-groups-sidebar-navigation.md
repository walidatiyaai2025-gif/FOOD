# V2 P190 — Customer Groups Sidebar, route authorization and tenant scope

- **Issue:** #2520, parent #2503; source: FOODEX Unified RBAC/ERP/Sidebar discussion, Sidebar P190 (native DOCX paragraph P179; stable matrix index P190).
- **Only integration destination:** `Foodex_V2`; no main, release, APK, production changes or deployment.
- **Business domain / entity:** Customer & Sales → Customer Groups; existing canonical Dashboard entity `commercial_customer_groups` and store/customer memberships.
- **User goal:** view, search and administer real customer segments for the currently authorized Wholesale or Retail store; no new screen required.
- **Placement:** **Reuse** the existing Customer Groups Data Explorer / Grid and existing authorized Sidebar `accounts` group. No new navigation primitive, dropdown, route, controller, database table or separate visibility authorizer. Existing mobile-web Sidebar drawer and shared page components remain the responsive owner.
- **Navigation type:** `SIDEBAR`, group `accounts`; entry points `customer_groups` for B2B and `b2c_customer_groups` for B2C. The matching customer workspace and Sidebar back path are retained. Absent authorization means the destination is not exposed and a manually crafted request must still be rejected.

## Canonical source and security contract

| Business context | Sidebar key | HTTP GET / named route | Permission and scope | Runtime protection |
| --- | --- | --- | --- | --- |
| Wholesale | `customer_groups` | `/admin/b2b/customers/groups` / `admin.b2b.customer-groups.index` | `customers.view`, authorized B2B store | `CustomerGroupManagementController::authorizedStore` → `OperationalTenantScope::assertStore` |
| Retail | `b2c_customer_groups` | `/admin/b2c/customers/groups` / `admin.b2c.customer-groups.index` | `customers.view`, own assigned active Retail B2C store only | Same canonical guard, `group_channel=b2c`, store assignment and tenant isolation |
| Mutations | Existing Create / Edit / Membership actions | Existing `POST`/`PATCH` routes in `backend/routes/web.php` | Independent `customers.manage` plus store/customer validation, never granted merely by a visible View link | Controller transaction, `commercial_customer_group_members` scoping and `AuditLogger` |

- **Metadata / entitlement:** `backend/config/admin_sidebar.php` keys and `AdminNavigation::groupsFor` are an already authorized navigation *projection*, not a grant. This is a canonical, operational Customer Groups page, not an ERP module toggle; no speculative `is_enabled` bypass or standalone module readiness assertion is introduced. Existing role, tenant, feature and store-active guards remain authoritative.
- **Localization:** existing `customer_groups.wholesale_title` and `customer_groups.retail_title` keys are reused for Arabic and English. Existing Dashboard root direction controls RTL/LTR. No raw technical codes are shown in the UI.
- **Data boundaries:** no read or write across foreign B2C stores, no B2B access inferred from a B2C store-role assignment, no implied `customers.manage` from a menu link. Inactive/roleless/global DENY should not produce an authorized menu or a successful direct request.
- **Failure path / recovery:** no migration, records, uploads, permission grants or configuration mutations. CI rollback is ordinary reversion of this test-only change. An unauthorized link is not made safe merely by hiding it.

## Automated regressions

`backend/tests/Feature/V2CustomerGroupsSidebarNavigationAuthorityTest.php` exercises:
1. Mixed-role B2B + assigned Retail B2C actor: exact two existing links in the Accounts group, matching canonical route and AR/EN label in RTL/LTR, successful real controller HTTP GET, correct page `navContext`, Retail group rows restricted to the assigned store.
2. An explicitly foreign Retail store URL returns a denial rather than exposing another store.
3. Roleless and global explicitly DENY users gain neither a Sidebar route nor permission from a direct URL.
4. Deactivated Retail store is not a legal customer-group destination.

Related existing `CustomerGroupManagementTest` covers actual create/update/membership/bulk transaction, audit and customer/store isolation. This Issue reuses those capabilities; it does not duplicate or weaken them.

## Surface and delivery impact

| Surface | Classification and evidence |
| --- | --- |
| Backend / Dashboard | **Test and acceptance documentation only**. Existing `AdminNavigation`, `CustomerGroupManagementController`, `OperationalTenantScope`, routes and views are unchanged; controller-backed GET and store scoping are exercised. |
| Customer Flutter | **Not affected** — no Customer app, API schema, authentication, catalog, pricing or mobile request behavior changed. |
| Driver Flutter | **Not affected** — no Driver app, B2C delivery/dispatch, geolocation or API behavior changed. B2C remains Driver-only. |
| Van Flutter | **Not affected** — no Van app, B2B visits/dispatch/routing, collection or API behavior changed. B2B remains Van-only. |

## Verification and remaining manual acceptance

Required before isolated merge: Repository Policy, FOODEX V2 Isolation Gate, Arabic/English localization, PHP syntax/Pint/PHPStan/security/OpenAPI, full Laravel tests, real MySQL/Redis fresh install/upgrade/recovery and final `required-ci-gate` must succeed on **exact PR HEAD**. Do not infer verification from a successful PR build alone, older SHA or skipped mobile jobs.

**MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL** only if the source scope and all impacted automated gates are genuinely complete and the PR has merged into `Foodex_V2`. Independent browser/device checks at 320/360/390/412/430/768/1024/1280px, keyboard/focus/Escape, screen-reader, permission projections and empty/error states belong to #2503/#2750 final V2 gate, and are not claimed to be performed here. This document does not authorize main or production promotion.

# V2 P189 — Retail Customers Sidebar, real route and scope

**Source:** FOODEX Unified RBAC / ERP / Sidebar discussion, index P189. **Issue:** #2519; **parent:** #2503. **Integration branch:** `Foodex_V2` only.

## Reuse and navigation contract

- **Domain / entity:** Customer & Sales → Retail Customers; real B2C customers for an assigned Retail store.
- **Role:** active `B2C_STORE_ADMIN` (and other Retail roles whose effective `customers.view` is authorized for their own store); never an ordinary Wholesale-only or unprivileged account.
- **User goal / workflow:** open and inspect a store's actual Retail customer list; independent management permissions apply to mutations.
- **Placement:** **Reuse** the existing Customers module inside the B2C workspace; canonical Sidebar group `accounts`, key `b2c_customers`. Navigation type `SIDEBAR`, parent Retail customer workspace. This is a Data Explorer / Grid, not a new page, duplicated controller or second RBAC engine.
- **Existing route:** `admin.b2c.module`, GET `/admin/b2c/{module}` with `module=customers` and exact authorized `store_id`; real controller `B2cWorkspaceController::show`, view with `navContext=b2c_customers`.
- **Permission:** `customers.view` scoped to an active Retail B2C store assignment. `AdminNavigation::module` chooses the matching store using `retailStoreForModule`; independently `B2cWorkspaceController::workspaceContext` / `authorizeModule` enforce store and channel against direct URLs. No display/personal-hide setting can grant server permission.
- **Module/readiness:** existing Retail Customers module in the canonical B2C workspace, controlled by `PROFILE_MODULES` and role/store status. Not a new ERP enabled flag. A store with inactive/foreign/non-Retail profile is not a valid workspace. No fallback into B2B.
- **Localization:** `admin.b2c_workspace.modules.customers` and existing Dashboard root `dir=rtl/ltr` in AR/EN. Existing mobile web navigation drawer is retained, with no new duplicated UI.
- **Back path / failure:** existing B2C Dashboard context and shared navigation; direct forbidden or inactive-store URLs must return server-side denial, never a merely hidden item or stale customer list.

## Explicit tests and security

The new `backend/tests/Feature/V2RetailCustomersSidebarAuthorityTest.php` checks:
1. A store-assigned Retail admin has one canonical `b2c_customers` link, exact store parameter and label in AR RTL / EN LTR; the real page returns HTTP 200 with the expected selected store and context.
2. An explicitly foreign Retail store URL is forbidden / not found.
3. Roleless, Wholesale-only and explicit global `customers.view` DENY actors do not receive Retail Customers navigation and are rejected on direct HTTP.
4. A deactivated Retail store cannot grant a valid Sidebar destination or successful direct request.

Existing B2C workspace tests cover list data, profiles and business mutations. This change adds isolated V2 navigation/HTTP regression only; no production data, role templates, permission grants, user overrides, migrations, APIs or deployed assets change.

## Per-app impact

- **Backend:** additive Laravel test only; production authorization/route remains canonical and unchanged.
- **Dashboard:** additive acceptance documentation and validation only; existing responsive/RTL/LTR page and shared Sidebar are reused without markup or CSS edits.
- **Customer:** **NOT AFFECTED** — no Flutter application, Customer identity, API shape, products, ordering or pricing logic changed.
- **Driver:** **NOT AFFECTED** — no B2C dispatch, driver GPS/status or API changed; B2C delivery stays DRIVER ONLY.
- **Van:** **NOT AFFECTED** — no B2B routing, van visit/collection or API changed; B2B fulfillment stays VAN ONLY.

## Exit gates

PR must target only `Foodex_V2` with latest exact-head Repository Policy, V2 Isolation, full Required CI children (PHP syntax, Pint, PHPStan, security, OpenAPI, Laravel, AR/EN and MySQL/Redis fresh-install/upgrade/recovery) all successful before integration. No bypass, direct integration commit or stale-head merge.

`MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` per owner's latest parent #2503 directive, **only after** full code/CI acceptance. Real-browser responsive (320/360/390/412/430/768/1024/1280px), RTL/LTR keyboard/assistive-technology, empty/loading/error and actual cross-store operational acceptance remain final-V2 gates at #2503/#2750. This is not a production release or UAT approval. No `main`, release ZIP, tags, APK publication, cPanel or production changes.

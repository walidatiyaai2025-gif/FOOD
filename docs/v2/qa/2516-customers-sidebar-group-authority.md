# V2 #2516 — Customers group, P186 / actual document P175

**Parent:** #2503. **Integration:** `Foodex_V2` only. **Scope owner:** #2516, new test and QA files only. No direct code push to the isolated integration branch, no `main` changes, release or production deployment.

## Placement and source resolution

The source's `العملاء` is a **business navigation group**, not a command/independent HTTP route. Reuse the existing `accounts` group in `AdminNavigation::groupsFor` and `AdminSidebarManager`, with locale key `admin.nav_groups.accounts` (current labels: `العملاء والحسابات` / `Customers & Accounts`). This group already contains the canonical customer workflows; creating a new `customers` group or adding a second customer page would duplicate ownership. Exact source literal naming can be reviewed alongside the adjacent source-wide Sidebar naming initiative #2904; this test proves existing production label localization but does not claim it is a newly introduced label.

| Placement field | Decision |
| --- | --- |
| domain / entity | Customer & Sales / customer accounts and customer groups |
| actor / role | Authorized B2B global staff; Retail store-scoped staff; denied Driver/roleless actors |
| user goal | Find customer list, Customer 360 and authorized groups without guessing URLs |
| workflow / frequency / density | Frequently used read/manage workbench and searchable customer lists |
| existing parent | `accounts` sidebar group, with `admin.nav_groups.accounts` title |
| placement_type / pattern | Reuse → existing business group and canonical Customer 360 or Retail workspace |
| navigation impact | No additional group, route, renderer, ability, entitlement or presentation-only security |
| desktop/tablet/mobile | Existing Admin shared sidebar; responsive off-canvas mobile navigation and touch/focus patterns |
| rtl_ltr | Existing Arabic/RTL and English/LTR shared shell and navigation translations |
| states | authorized children shown in registry order; denied/expired role and zero-child group suppressed; HTTP direct access forbidden |
| fallback / back path | authorized Overview/workspace, standard Sidebar; no forced deep-link |
| module readiness | Core customer routes, not optional uncertified ERP module; no readiness bypass or new entitlement |

## Canonical authorized destinations

| Child key | Business meaning | Route | Permission / scope |
| --- | --- | --- | --- |
| `customer_360` | Customer 360 | `admin.customer-360.index` | effective `customers.view` (or authorized B2B `b2b.accounts.view` fallback); controller `Customer360Controller::access` resolves records and scope |
| `b2c_customers` | Retail customers | `admin.b2c.module` with `module=customers`, `store_id` | `customers.view` at assigned B2C `RETAIL_STORE`; `B2cWorkspaceController::show` + TenantContextResolver |
| `customer_groups` | Wholesale groups | `admin.b2b.customer-groups.index` | authorized B2B global role + `customers.view`; same group-management controller guards tenant/channel |
| `b2c_customer_groups` | Retail groups | `admin.b2c.customer-groups.index` | B2C scope + `customers.view`, checked again at HTTP boundary |

Wholesale customer list is an existing B2B `admin.b2b.module` `module=clients` workspace, and the Customer 360 entry exposes authorized cross-channel customer records. #2518 independently owns the wholesale customer child; #2519 owns Retail Customers; #2520 owns Customer Groups; #2517 owns Customer 360. This group Issue neither duplicates those implementation files nor invents an unauthorized shortcut.

The group heading itself contains no `route` field and provides **no permission**. All child links come from effective navigation permissions and remain subordinate to independent HTTP authorization. Legacy Sidebar configuration can alter presentation only, not turn forbidden data routes into allowed ones. Empty groups are removed by `AdminNavigation::groupsFor`. The permission set and route guard are the security authority, never a checkbox.

## Regression and negative proofs

`backend/tests/Feature/V2CustomersSidebarGroupAuthorityTest.php` uses a seeded isolated Laravel database and checks:

1. Retail manager in Store A sees exact ordered customer navigation children and can open canonical customers + groups pages in **Arabic RTL** and **English LTR**; Store B forged URL is rejected.
2. B2B operator sees only wholesale-appropriate Customer 360 and Groups children, opens both real routes, and is denied direct Retail customer management.
3. Mobile Driver role is denied customer management routes and has no empty Customers group.
4. Expired/deactivated Store Admin role loses navigation and direct customers URL access.

The pre-existing role, controller, tenant, localization, responsive and accessibility checks are retained. No UI markup changes were made, so the existing shared layout/keyboard/focus/loading/empty-state mechanisms are reused. Real viewport/device/browser UAT is **not claimed**, per owner #2503 it is `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` at #2750. Automated security and HTTP regressions remain mandatory now.

## Cross-surface classification

| Surface | Impact | Evidence |
| --- | --- | --- |
| Laravel Backend / Dashboard | Regression tests + documentation only | No route, controller, permission, schema, view or persisted data changes |
| Customer Flutter | NOT AFFECTED | No Customer API, account schema, auth, deep-link, notification or runtime changes |
| Driver Flutter | NOT AFFECTED | No mobile Driver auth or delivery APIs changed; negative test denies Driver Dashboard access |
| Van Flutter | NOT AFFECTED | No Van application, dispatch, visit, data or assignment contract modified |

B2B fulfillment remains **VAN ONLY**, B2C fulfillment remains **DRIVER ONLY**. No new mobile integration issue is warranted without a changed API/mobile behavior.

## Completion gate

Confirm final exact-head Repository Policy, V2 Isolation, full Laravel tests, PHP syntax/Pint/PHPStan/security/OpenAPI, localization, MySQL/Redis install + supported ZIP upgrade/recovery acceptance, terminal `required-ci-gate` **SUCCESS** before merging solely into `Foodex_V2`. Record SHA, CI run IDs, merge SHA and scope-specific acceptance on #2516 before marking it completed. Any failed test requires a fix on the **same** branch and PR. No green claim based only on an in-progress build.

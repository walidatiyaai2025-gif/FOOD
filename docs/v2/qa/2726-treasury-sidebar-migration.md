# V2 #2726 — Treasury Sidebar migration (T356R9)

**Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, row `T356R9`. Parent #2503. Target integration branch **`Foodex_V2` only**.

## Placement, audience and authority

- **Domain / entity:** Finance & Treasury / Treasury master data (cashboxes and bank accounts).
- **Persona / user goal:** Authorized B2B finance-capable manager opens treasury reference data, reviews cashboxes/bank accounts and uses existing forms for bounded financial configuration. Retail Finance actors retain *their already supported store-scoped direct Treasury master-data access*.
- **Frequency / density / screen pattern:** Periodic financial administration; existing **Settings / Master Data** page with filtered, paginated cashbox and bank account grids and exact-record edit actions.
- **Placement choice:** **Reuse**, not Extend/Compose/New Screen. Existing Finance & Treasury group (`finance`), original Sidebar item `treasury_master` and `admin.treasury.master.index`, rendered by `TreasuryMasterController::index` + `admin.treasury-master` in the shared Dashboard shell.
- **Navigation owner/type:** `AdminNavigation::groupsFor`, existing `finance` SIDEBAR group; `routeItemScoped` projects existing effective `finance.view` and platform-navigation eligibility. Original route, key, params, authorization and active `navContext=treasury_master` are unchanged.
- **Business display:** Sidebar `treasury.nav_title` changes from `البيانات الأساسية للخزينة` to requested **الخزينة**, and from `Treasury master data` to **Treasury** in English. The existing descriptive page heading `treasury.title` deliberately remains **البيانات الأساسية للخزينة / Treasury master data**, which correctly distinguishes this master-data page from the larger ERP treasury workbench.
- **Route fallback/back:** existing Finance group and contextual ERP Treasury workbench cross-links. No dead URLs or parallel navigation destinations.
- **Permissions and security:** All existing `finance.view`/ `finance.manage`, operational tenant scopes, store-level bank/cashbox visibility, audit, encrypted bank references, approval separation and server-side mutation authorization remain intact. A shorter label is **not** a privilege grant.
- **AR/EN, RTL/LTR and accessibility:** Existing shared Dashboard/mobile-web renderer, focus treatments, tab/grid/lookup semantics and responsive shell retained unchanged; no HTML/CSS/JS introduced. Validate isolated V2 staging at 320, 360, 390, 412, 430, 768, 1024 and 1280px for overflow, keyboard/focus and mobile ergonomics. Browser/assistive-technology acceptance cannot be inferred solely from HTTP tests.

## HTTP / tenancy and negative test matrix

| Actor | Projected link | Existing authorized behavior |
| --- | --- | --- |
| B2B_ADMIN with `finance.view` | Exactly one `treasury_master` under Finance & Treasury; label `الخزينة / Treasury` | Named `admin.treasury.master.index` GET 200 in AR RTL and EN LTR; existing page title and mobile-web marker |
| B2C Retail Finance assigned Store A | No **platform-level** Treasury Sidebar grant (existing policy) | Scoped master-data GET for Store A 200; foreign Store B GET 404 |
| Same Retail Finance forging Store B cashbox creation | No additional grant | POST to original `admin.treasury.cashboxes.store` denied 404, no cashbox row created |
| Active user without roles | Treasury link hidden | Direct Treasury route GET 403 |

New `backend/tests/Feature/V2TreasurySidebarMigrationTest.php` covers actual navigation projection, exact named routes, paired localization, authenticated HTTP, forbidden cross-store finance read/write and denied role-less access. Canonical `backend/tests/Feature/Enterprise/TreasuryMasterFoundationTest.php` already verifies authorized Retail Finance create/edit, encrypted bank reference, store separation and bilingual page heading; it remains unchanged and must stay green.

## Backend / Dashboard / mobile impact classification

| Surface | Effect |
| --- | --- |
| Laravel Backend | Only two existing translation `nav_title` values and a new feature regression test; **no** finance controller, money accounting, DB migration, security middleware, API/OpenAPI, tenant resolver or dependencies changed |
| Dashboard | Finance & Treasury → Treasury shorter human-readable Sidebar title; detail heading, tabs, forms, permissions, read-only/error/empty and responsive states unchanged |
| Customer Flutter | **Not affected:** no customer API, wallet/credit, invoices, Flutter code, identity, payload or navigation contract changed |
| Driver Flutter | **Not affected:** no driver cash collection, settlement, route, API or Flutter code changed |
| Van Flutter | **Not affected:** no van sales/collections, finance, field operations, API or Flutter source changed |

B2B fulfillment remains Van-only; B2C fulfillment remains Driver-only. No app contract has changed, so no app-specific V2 mobile issue is necessary for this Dashboard-only label change.

## CI and completion contract

On the exact PR head, require Repository Policy, FOODEX V2 Isolation Gate, `required-ci-gate`, Laravel Backend tests, PHP syntax/Pint/PHPStan/Composer security/OpenAPI, AR/EN localization and MySQL/Redis real setup/upgrade/recovery. Tests: `php artisan test --filter=V2TreasurySidebarMigrationTest`, `php artisan test --filter=TreasuryMasterFoundationTest`. Only merge via one PR into `Foodex_V2` after success; no `main`, release, tag, APK or production deployment.

Do not close issue #2726 until independently verifiable isolated-staging mobile and desktop browser acceptance is complete. No new release ZIP is produced by a UI translation-only PR.

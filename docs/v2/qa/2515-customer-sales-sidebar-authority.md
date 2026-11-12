# V2 P185 — Customer & Sales / Customer Service navigation authority

- Issue: #2515; master #2503; source: Unified RBAC/ERP/Sidebar DOCX, Part 2 §4, P185 (check the canonical source-row mapping before final acceptance).
- Integration: `Foodex_V2` only. No `main` merge, production deployment, tag or release.
- Delivery classification: **bounded HTTP/RBAC characterization and source trace only**; the full P185 unified business-group acceptance is **NOT YET COMPLETE**.

## Placement and business ownership

- Domain/entity: Customer & Sales; customers, customer groups, order operations and support cases.
- Personas: Wholesale Customer Service Manager (global B2B), Retail Store Admin (store-scoped B2C), roleless and inactive/explicitly denied actors.
- Goal/workflow: discover authorized customers, customer groups and orders, then open the actual existing operational page.
- Usage: frequent, primarily lists/details with store context. Placement choice: **reuse** established groups and workbenches before any new Sidebar group or duplicate route.
- Current canonical Sidebar navigation owner: `backend/app/Support/AdminNavigation.php::groupsFor()`; current groups: `accounts`, `customer_service` and `operations`.
- Existing navigation type: `SIDEBAR`; customer group detail workflows stay in their canonical parent pages.
- Exact routes: `admin.b2b.customer-groups.index`, `admin.b2c.customer-groups.index`, `admin.customer-360.index`, `admin.operations.orders.index`; customer service workbench: `admin.erp.customer-service`.
- Route/permission: customer groups require `customers.view` for GET and `customers.manage` for mutation; orders require `orders.view`. Customer 360 may use `customers.view` or B2B `b2b.accounts.view` depending on the canonical actor path. Platform/Super Admin visibility is not the authorization source.
- Data scope: actual server-side `OperationalTenantScope`, channel B2B vs B2C and assigned Retail store; foreign stores denied, including direct HTTP requests. Explicit user DENY overrides Sidebar presentation settings.
- Module/entitlement/readiness: these currently reused customer group routes are not independently enabled by an ERP module readiness flag; do **not** project unready ERP actions as authorized based on menu visibility.
- AR/EN + RTL/LTR: existing `admin.nav_groups.accounts` translations are Arabic “العملاء والحسابات” and English “Customers & Accounts”; real HTTP customer groups use the Dashboard locale direction.
- Discoverability: via existing Dashboard side navigation; desktop Sidebar and mobile-web off-canvas share `groupsFor()`. Back/fallback: retain canonical Accounts/Orders workbenches and Dashboard navigation; do not create a direct-URL-only entry.
- Viewports and interaction acceptance: desktop, 1024/768 tablet, 320–430px mobile-web, keyboard and screen reader are deferred to the central real-runtime UX gate #2750; this code-only slice does **not** assert browser/a11y PASS.

## Automated regression introduced

`backend/tests/Feature/V2CustomerSalesSidebarGroupAuthorityTest.php` covers:

1. Real Wholesale Customer Service Manager customer-group route and existing operations entry in AR/EN with direction and route existence.
2. Retail Store Admin own-store allowed path; foreign-store and Wholesale direct requests forbidden/not found with no cross-channel navigation.
3. Presentation visibility cannot override explicit `customers.view` DENY; original role grants are preserved.
4. Roleless and inactive users cannot discover customer/sales operational entries; no rendered empty groups.

These are real Laravel Feature HTTP tests against the existing routes; no mock route or fake success response. A denial may be 403 or 404 depending on whether the canonical controller intentionally obscures inaccessible store existence; do not present this limited negative assertion as uniform 403 acceptance.

## P185 source acceptance gap — do not close this Issue

The source requests one **“العملاء والمبيعات وخدمة العملاء”** parent business group. The existing `groupsFor()` splits the related destinations among `accounts`, `customer_service`, `operations` and Sales Control elsewhere. This new regression does not merge groups, change Sidebar IA or certify complete P185 child ordering. That structural discrepancy requires a separate, conflict-free follow-up on the **same #2515 branch/PR** once current shared-file owners are clear. In particular, active PR #3246 for #2523 already owns `AdminNavigation.php`, `config/admin_sidebar.php`, routes and Sales Quotations. Do not overwrite/recreate those changes from this lane. This PR should remain a **draft** until the P185 source hierarchy and runtime accessibility criteria are actually implemented and verified; do not close #2515 on the test-only delta.

## Backend / Dashboard / apps impact matrix

| Surface | Assessment | Evidence and follow-up |
|---|---|---|
| Backend / Dashboard | Tests only. Existing navigation, route/controller/authorization left unchanged. | New Laravel Feature test exercises real navigation and HTTP. No permission or data mutation behavior introduced. |
| Customer Flutter | Not affected by this delta. | No `apps/customer_app` or `backend/routes/api.php` modification; Customer B2B/B2C product/checkout and API contracts unchanged. |
| Driver Flutter | Not affected by this delta. | No Driver app/API endpoint/status/dispatch changes; B2C → Driver remains unchanged. |
| Van Flutter | Not affected by this delta. | No Van app/API endpoint/route/assignment change; B2B → Van remains unchanged. |

No new mobile-impact issue is needed for a test-only Dashboard characterization delta. If the eventual P185 UI/runtime changes alter any app/API contracts, reopen individual app impact assessments and link distinct V2 mobile issues.

## Delivery and QA evidence

- Local syntax validation of the corresponding PHP test content: `php -l` reported no syntax errors (PHP 8.4); full repository dependencies and GitHub Actions are not available in this working container.
- Exact-head remote `Laravel / PHPStan / Pint / AR-EN / Repository Policy / V2 Isolation / Required CI` must be checked from GitHub PR; **not yet claimed green**.
- Before merge, reconcile against current `Foodex_V2` and PR #3246, inspect all changed files, validate no shared ownership conflict and all required child jobs on last PR SHA.
- Manual responsive/keyboard/a11y/staging acceptance is deferred to master #2503 and #2750 and is never claimed complete here.

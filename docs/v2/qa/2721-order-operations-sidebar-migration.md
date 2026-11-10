# V2 #2721 — Order Operations canonical Sidebar migration (T356R4)

- **Source:** FOODEX Unified RBAC/ERP/Sidebar Discussion (2026-11-09), T356R4.
- **Parent:** #2503; integration branch **Foodex_V2 ONLY**.
- **Actor/goal:** Authorized B2B operations/admin staff discover and open existing **Order Management**. Roleless/inactive or out-of-scope users must not acquire new access from menu placement.
- **Domain/entity:** Orders & Operations / Orders. No new domain controller, route, mutation or independent permission policy.
- **Placement (Reuse → Extend):** Reuse the existing canonical group `operations`, item key `order_operations`, route `admin.operations.orders.index` and effective permission `orders.view`. Existing orders UI and direct HTTP authorization remain authoritative; no new Sidebar destination, parallel orders page or duplicate links.

## Implemented

- The AR business group label `admin.nav_groups.operations` is now **الطلبات والعمليات**, with EN parity **Orders & Operations**. Existing `admin.order_management` remains **إدارة الطلبات / Order Management**.
- The canonical registry `AdminNavigation::groupsFor` and `AdminSidebarManager` remain unchanged; saved administrator-specific bilingual label/order overrides remain authoritative. The renamed default group provides discoverability for users without custom overrides, not an access grant.
- A new `V2OrderOperationsSidebarMigrationTest` proves one authorized item, correct group/route/key/permission/labels for AR RTL and EN LTR, a real authorized HTTP GET, plus roleless and inactive direct HTTP 403 with no corresponding link. Existing tenant/order authorization feature suites continue to guard cross-store and SoD behavior. No DB migration or user/role assignment is changed.
- Existing responsive Dashboard Sidebar/drawer render the same server-filtered tree; there is no separate mobile-web navigation policy. This change affects business labels, not route availability.

## Authorization and negative outcomes

| Actor/state | Sidebar | HTTP | Safety rationale |
| --- | --- | --- | --- |
| Active Wholesale B2B_ADMIN with `orders.view` | Exactly one `order_operations` link in `operations` | Authorized GET to canonical Orders route | Existing server route and effective role permission |
| Roleless authenticated user | No Orders item | 403 | Label/layout must not grant access |
| Inactive previously authorized B2B actor | No Orders item | 403 | Authentication/account-status boundary |
| Retail actor / foreign store | Existing scoped route/order guards unchanged | Tenant/store scope enforced by existing tests and controllers | No cross-channel or store grant |
| Legacy customized Sidebar label/group placement | Existing saved UI preferences retained | Same authorization | Presentation cannot rewrite access or business state |

## Customer / Driver / Van impact

- **Customer:** Not affected — no Customer Flutter source, public Orders API, credit, pricing, login or status contract changed.
- **Driver:** Not affected — no Driver Flutter/API/dispatch/GPS change; B2C = DRIVER ONLY.
- **Van:** Not affected — no Van Flutter/API/territory/collection/visit change; B2B = VAN ONLY.

## Acceptance and evidence

Exact PR HEAD must pass Repository Policy, FOODEX V2 Isolation Gate, AR/EN localization, PHP syntax/Pint/PHPStan/security/OpenAPI, full Laravel features and MySQL/Redis fresh-install/upgrade/recovery with final `required-ci-gate`; tests that are path-skipped do not count as executed. Only merge via a PR into `Foodex_V2`; do not touch `main` or release to production.

**MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL** per owner #2503. Final physical-browser responsive/keyboard/screen-reader and real-user/store/operations UAT remains centrally tracked in #2503/#2750; code-level CI alone never certifies production.

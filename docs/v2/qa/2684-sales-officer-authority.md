# FOODEX V2 — #2684 Sales Officer B2B authority (T122R04)

**Source:** FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx / T122R04; coordinator #2503; implementation target **Foodex_V2 ONLY**. Original issue branch `test/2684-v2-sales-officer-authority`, original PR #3222 preserved after handoff. Never merge/promote to `main` or publish production artifacts.

## Placement and authority — Reuse, not a new page

Persona `SALES_OFFICER` / مسؤول المبيعات is an existing seeded, tenant-global **B2B sales operator**. Source: `backend/config/job_roles.php` and `BuiltInJobRoleCatalog`. It carries exactly:
- `b2b.accounts.view`, `b2b.pricing.view`
- `orders.view`, `orders.edit`
- `customers.view`, `customers.edit`

No `orders.create`, `orders.approve`, `orders.manage`, `orders.dispatch`, `finance.*`, `roles.manage`, `users.delete` or Driver management grants. No Super Admin proxy role.

**Navigation:** Reuse existing **Operations → Orders** and **Accounts → Customer 360** and their canonical server-authorized controllers; no parallel Sidebar item, route or CRUD workflow. Domain = Sales; scope = Wholesale/B2B; navigation entry = existing visible page; permission = `orders.view`/`customers.view`; active context = B2B. Existing Dashboard shared shell renders desktop/mobile and AR/EN RTL/LTR; manual visual testing is deferred to #2503/#2750.

## Root cause and corrected implementation

Original test-only PR #3222 was DRAFT/RED because `SALES_OFFICER` was **absent** from the Wholesale channel allowlist (`backend/config/admin.php`) while its canonical job role carried scoped B2B permissions. This blocked normal Dashboard access even though the operator role was seeded. Previous shared-file owner #2699 / PR #3179 released its lease; #3179 closed without merge. Separately, #2695 / PR #3191 merged into Foodex_V2 before this change and released the same configuration file.

In this resumed **same** PR, append only `SALES_OFFICER` to `admin.channels.b2b.global_roles`. Preserve every other role and leave `admin.channels.b2c.global_roles` unchanged. No new grant, permission override, API endpoint, event, migration or financial approval bypass. Real Orders and Customer routes still enforce channel/store scope and per-action permissions.

Historical issue branch SHA `d9ec9eae0ce47b9fbdf8725a0a462a5710dd72ba` and original test/docs are preserved through an integration commit with `Foodex_V2@efced7112b4c46d6c16bfbee690f3f5c1bc86830`.

## Automated tests and security

`backend/tests/Feature/V2SalesOfficerRoleAuthorityTest.php` covers:
- Canonical seeded role bundle, exact least-privilege grants and localized role presentation in Arabic and English.
- B2B navigation discoverability, real authenticated Orders HTTP 200 and Customer 360, B2C channel Orders HTTP 403, security HTTP 403, no Retail role/domain leakage.
- Wholesale scoped `orders.view` and `orders.edit`, no approval or dispatch; real Orders AR/RTL and EN/LTR response `dir`.
- User-specific explicit DENY removes Orders/Customer navigation and rejects direct Orders HTTP without authorization via hidden links. **Customer 360 has two alternative independent read guards**: `customers.view` and `b2b.accounts.view`; both must be explicitly denied before its navigation may correctly disappear. A DENY of only one does not revoke the other authorized grant.
- Inactive identity fail-closed with no inherited permission/visible page.

No UI field, lookup, enum, builder, route or state transition is introduced. For the existing Orders grid, canonical shared responsive controls and no-extra-action behavior remain unchanged.

## Application impact matrix

| Surface | Impact | Proof |
| --- | --- | --- |
| Laravel Backend | **Affected** | B2B channel allowlist only; no service/tenant/API contract changed |
| Admin Dashboard | **Existing entry authorized** | Reuse Orders/Customer 360 and direct server-side guards; AR/EN HTTP tests |
| Customer Flutter | **Not affected** | No customer API/auth/order/catalog payload change or Flutter modification |
| Driver Flutter | **Not affected** | No B2C Driver-only dispatch, proof or wallet contract change |
| Van Flutter | **Not affected** | No B2B Van-only visit, routing, collection or assignment contract change |

B2B=VAN ONLY and B2C=DRIVER ONLY remain authoritative.

## CI recovery evidence (13 November 2026)

- The first full Laravel run for PR #3222 on `353b09e3be4ca2b3bbfd007c69f2934ecb8e5f1b` finished **3439 passing, one failing**; Pint/PHPStan/security, AR/EN, V2 Isolation, Repository Policy, MySQL/Redis Fresh Install/Upgrade/Recovery all passed. The only failed assertion expected `customer_360` to disappear when `customers.view` was DENY even though `b2b.accounts.view` was still independently allowed. The existing canonical Sidebar correctly uses its explicit fallback read grant; this is **not a permission bypass**.
- The follow-up test fixture explicitly denies both supported Customer 360 read capabilities and checks the post-DENY effective permission. No runtime authorization logic or production permission set was widened or weakened. Full exact-head Required CI must rerun on the final fix commit before any merge; old green child checks are not sufficient.

## Required acceptance and closure

Merge **only** into `Foodex_V2` on the **exact latest** PR HEAD after Repository Policy, FOODEX V2 Isolation Gate, backend PHP syntax/Pint/PHPStan/security, AR/EN localization, full Laravel tests, MySQL/Redis fresh-install/upgrade/recovery and terminal `required-ci-gate` all report SUCCESS. Record tested SHA, workflow IDs, merge SHA, verify target integration and close source-complete code leaf. Do not claim success on older/partial CI. Manual browser/device/UAT is **DEFERRED_TO_V2_FINAL** under #2503/#2750, not certified now. No `main`, release, APK deployment, tags or production changes.


## Deactivated-identity source repair (13 November, follow-up)

- A fresh Required CI run on `82942d41f25ddc527e1fee830ab28947f4388ff5` passed all gates except **one** source regression: 3439 tests passed, `V2SalesOfficerRoleAuthorityTest.php:156` failed because a deactivated user still returned true from `User::hasPermission('orders.edit')`. The model checked assigned roles and direct overrides without checking `is_active`. Operational HTTP/tenant guards already independently filter disabled accounts, but that does **not** establish a fail-closed reusable domain permission method.
- `backend/app/Models/User.php::hasPermission` now denies **all** effective permissions when `is_active` is false, before the SUPER_ADMIN shortcut, inherited role permissions and direct ALLOW overrides. It does **not** delete historical `role_user` or `user_permission_overrides` records; those remain auditable. This is an actual security correction required by the inactive Sales Officer acceptance path, not a weakened test.
- Added focused assertions that disabled Sales Officer and disabled SUPER_ADMIN cannot retain effective permissions despite historic role assignment, while active roles continue to work. No admin route, B2C permissions, financial SoD or public/customer APIs were introduced.
- The current branch needs full **new exact-head** Required CI including full Laravel, security, localization, MySQL/Redis and terminal `required-ci-gate` before the original PR #3222 can be merged. Previous failing/green-partial runs cannot count as approval.

## Exact-head CI rescue: partially hydrated users and explicit active fixtures (2026-11-13)

The previously failing [Required CI #43384731774](https://github.com/walidatiyaai2025-gif/FOOD/actions/runs/43384731774) on `8d6e4dbce196740bcfca5e7e75b0973bc2979cfa` passed Policy, Isolation, AR/EN localization, backend fast-fail/security and real MySQL/Redis deployment acceptance, but failed six full Laravel tests. The new fail-closed `User::hasPermission()` inactive-user guard is required for security and must not be weakened.

Five failing `RolePermissionTest` expectations used synthetic grant recipients created without explicit `is_active`; the test fixtures now make these *authorized active* actors explicit (while the #2684 negative tests still assert inactive-deny). One failing `AppPreviewDashboardBridgeTest` expected the two authorized active Retail drivers in a store but received none: `AppPreviewTargetService::drivers()` selected only `id/name/locale` before calling `User::hasPermission()`, making its partial User models appear inactive. The driver target query now selects `is_active` after the existing SQL active filter, preserving the required permissions, store and channel predicates and inactive-driver exclusion. The existing bridge regression remains the HTTP contract; no simulated target or unsafe fallback is introduced.

Files added to the original issue's bounded CI-rescue scope are `backend/tests/Feature/RolePermissionTest.php` and `backend/app/Services/AppPreviewTargetService.php`. Production mobile API payloads, Flutter code and dispatch behavior are unchanged; Customer: unaffected; Driver: unaffected app contract (admin-only preview selection bug fixed); Van: unaffected. Do not mark #2684 code complete until **new final-head** Policy, Isolation and Required CI (Laravel full tests, AR/EN, static/security, MySQL/Redis and terminal gate) all pass, source reviews clear and original PR #3222 merges exclusively into `Foodex_V2`. Manual device/browser UAT remains deferred to #2503/#2750. No `main`, release, tag or production operation.

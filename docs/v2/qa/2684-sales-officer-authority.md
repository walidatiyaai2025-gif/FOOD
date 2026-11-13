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
- User-specific explicit DENY removes Orders/Customer navigation and rejects direct HTTP, without authorization via hidden links.
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

## Required acceptance and closure

Merge **only** into `Foodex_V2` on the **exact latest** PR HEAD after Repository Policy, FOODEX V2 Isolation Gate, backend PHP syntax/Pint/PHPStan/security, AR/EN localization, full Laravel tests, MySQL/Redis fresh-install/upgrade/recovery and terminal `required-ci-gate` all report SUCCESS. Record tested SHA, workflow IDs, merge SHA, verify target integration and close source-complete code leaf. Do not claim success on older/partial CI. Manual browser/device/UAT is **DEFERRED_TO_V2_FINAL** under #2503/#2750, not certified now. No `main`, release, APK deployment, tags or production changes.

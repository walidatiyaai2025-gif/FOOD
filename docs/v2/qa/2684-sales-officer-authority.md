# V2 #2684 — Sales Officer authority / T122R04

**Source:** FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, T122R04. Parent #2503. Integration is **Foodex_V2 only**; no main, tags, release, staging or production activity.

## Canonical role and placement

The existing `SALES_OFFICER` role is defined in `backend/config/job_roles.php`, seeded by `CoreReferenceSeeder`, and localized in `backend/lang/{ar,en}/job_roles.php`. Its six allowed operations are read-only B2B account/pricing access, read/edit orders and read/edit customers. It has no order approval, dispatch, finance posting, RBAC administration or Retail store role. Preserve this least-privilege set; do not create a duplicate role or second authorization engine.

**Placement:** Reuse the existing `Accounts → Customer 360` and `Operations → Orders` routes. A Sales Officer must see a usable B2B context and only B2B records available to the actor. Existing operational controllers and `OperationalTenantScope` remain server authority; navigation is not a grant. AR/EN role presentation comes from the existing catalog, and normal Dashboard RTL/LTR and responsive components are reused rather than duplicated.

## Verified source-level blocker, not yet fixed

`backend/config/admin.php` declares `admin.channels.b2b.global_roles` without `SALES_OFFICER`. This contradicts the B2B sales role's canonical permissions: `AdminNavigation::canAccess($actor, 'b2b')` returns false, so the normal B2B channel entry point is unavailable. The new executable test `V2SalesOfficerRoleAuthorityTest::test_sales_officer_has_wholesale_sales_entry_points_but_no_retail_or_privileged_access` exposes that omission and expects authorized B2B access with forbidden B2C access. Until this defect is repaired, the issue is **BLOCKED** and neither test suite success nor acceptance should be claimed.

At preflight, open PR #3179 (V2 Treasury Officer) also owns `backend/config/admin.php`; do not modify this file concurrently. Coordinate after its ownership is resolved and continue **this same branch/PR**; avoid duplicating the configuration change or creating another PR.

## Regression and app impact

`backend/tests/Feature/V2SalesOfficerRoleAuthorityTest.php` asserts:

- Exact canonical and seeded six-permission operator role, AR/EN labels.
- B2B-only navigation and HTTP order entry; explicit Retail isolation and security-route denial.
- No create/approve/dispatch/finance/administrative privilege inheritance.
- Per-user global explicit DENYs remove authorized Sidebar items and HTTP access; inactive accounts fail closed.

**Customer:** Not directly affected by these test/document-only changes. No Customer Flutter/API/pricing/auth contracts changed. Existing B2B customer access must remain confined to approved account scope; B2C remains Driver-only.

**Driver:** Not directly affected. No dispatch, Driver auth, B2C store scope or app contract changed. An officer is not a B2C Driver or dispatcher.

**Van:** Not directly affected. No Van visit, assignment, B2B fulfillment or API contract changed. B2B remains Van-only.

If the final B2B authorization correction changes a shared API or any mobile-visible role/route/response, open and link the actual impacted Customer/Driver/Van issue separately before integration.

## Reproduction and coordination checkpoint

1. Seed `CoreReferenceSeeder`; attach only `SALES_OFFICER` to an active user.
2. Observe `hasPermission('orders.view') === true` while `AdminNavigation::canAccess($actor, 'b2b') === false`; no independent Retail role must be added to mask the defect.
3. After #3179 releases `backend/config/admin.php`, update the canonical B2B allowlist in **this existing PR**, verify `admin.operations.orders.index?channel=b2b` and that Retail `channel=b2c` is still forbidden.
4. Check Customer / Driver / Van impact after any actual authorization-code change, then rerun the exact-head Required CI suite.

## Required evidence before merge/closure

Run the focused Laravel test against seeded database, PHP syntax/Pint/PHPStan and all exact-head required GitHub checks (Repository Policy, V2 Isolation Gate, Required CI with Laravel and relevant MySQL acceptance). Keep the PR **draft** while the intentional admission test is RED; inspect precise failure before updating either test or code. Once ownership clears, fix the authoritative channel allowlist without changing unrelated financial roles, then verify positive wholesale navigation/HTTP, negative Retail authorization, store/tenant scope and role/deactivation cases.

Record exact PR head, CI runs and merge SHA. Close only after the full leaf source acceptance is met. Manual visual/staging/UAT is deferred to V2 final per master #2503; no production/release claim is authorized.

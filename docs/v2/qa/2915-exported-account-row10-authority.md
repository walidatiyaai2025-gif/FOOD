# V2 #2915 — Table 5 data row 10, least-privilege scope audit

## Scope and source boundary

Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, native **Table 5, data row 10**. Mapping is recorded by `docs/v2/V2_ACCOUNT_EXPORT_AUDIT_MATRIX.json`; parent #2503. All delivery targets `Foodex_V2` only.

**Historical identity not certified.** The public Issue/matrix only identifies a row; it does **not** provide its historical role/profile, or a current-authorized account snapshot. Do not guess the role, make a role correction, reclassify or delete a historical customer, Driver or Van account, or post identifying exports, phone numbers, emails, tokens or secrets on GitHub. The `RETAIL_CUSTOMER_SUPPORT` actor in the test is a **hypothetical disposable fixture**, not a statement that the row-10 account has this role. Actual row-to-current reconciliation remains an OPEN acceptance item.

## Verified source contracts and executable synthetic regression

- Source authority: `User::hasPermission`, `User::hasRole`, `User::inheritedPermissionCodes`, `User::effectivePermissionCodes`, `OperationalTenantScope::allowedStoreIds`, and `AdminNavigation::groupsFor`; immutable `roles.code`, audited `user_store_roles` and `user_permission_overrides` remain authoritative.
- Test: `backend/tests/Feature/V2ExportedAccountRow10AuthorizationTest.php` (fresh CoreReferenceSeeder and disposable DB).
- Synthetic Retail support at store A has `orders.view` only with its active store assignment; store B and Wholesale remain inaccessible; `users.delete` cannot originate from store assignment. Check effective scope and denial of B2B orders and Security Center via direct HTTP, plus Sidebar non-leak in **AR and EN**.
- A legacy store `ALLOW` cannot negate the global `DENY` of the same permission; inherited permission remains in history but not in effective permissions/tenant scope. Inactivation must deny without deleting role or override provenance.
- No hardcoded identity from DOCX, live account lookup, production mutation, speculative privilege granting, database migration, new route, entitlement relaxation or UI-only hiding.

## Current-to-historical audit requirement — outstanding

An independently authorized, least-privilege **read-only** reviewer must privately correlate the row-10 historical record to the current identity and record **only non-identifying** review results: account type (Dashboard/Customer/Driver/Van), active/disabled status, global/store role pivots, exact tenant and channel scope, inherited vs effective permission code differences, global/store `ALLOW`/`DENY` override intersections, Sidebar exposure vs direct HTTP 403/allow, sensitive action/maker-checker combinations, disabled-module behavior and any necessary reviewed remediation. No live identity, credentials or irreversible mutation may enter an Issue, PR, artifact or test. A synthetic test passing does not complete this step.

## Cross-app and UI matrix

| Surface | Effect |
| --- | --- |
| Backend/RBAC | Test-only replay of existing stored role/override semantics; no runtime code/API/schema change |
| Dashboard | Existing Sidebar and protected B2B Security/Orders HTTP tested in AR/EN; no new UI/layout/route and no RTL/LTR rendering change |
| Customer | Not affected: no auth/customer API/Flutter source change, no pricing/credit/order semantics change |
| Driver | Not affected: no Driver API or execution/dispatch/Flutter change; B2C Driver-only invariant preserved |
| Van | Not affected: no Van assignment/API/Flutter change; B2B Van-only invariant preserved |

## Release gates and disposition

Use one original branch `test/2915-v2-row10-effective-role-scope-audit`, one PR targeting `Foodex_V2` only. Require exact-final-head Repository Policy, FOODEX V2 Isolation and FOODEX Required CI (PHP syntax/Pint/PHPStan/security/OpenAPI, Arabic/English, Laravel and applicable MySQL/Redis jobs) all green before integrating any bounded regression. Keep **#2915 OPEN** after integration until the historical row-to-live correlation and account-specific remediation evidence are complete. Manual UI/device UAT remains within #2503/#2750, never claimed as performed by this test. No main merge, tag/release, production deployment or live account edits.

# V2 #2909 — historical Retail Store Admin account / single-store authorization audit

## Historical source and privacy scope

- Parent: #2503; source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, **native DOCX Table 5, data row 4** (zero-based data row convention used by sibling account issues).
- The historical source lists exactly one `B2C_STORE_ADMIN` assignment at **one Retail store** with **53 distinct inherited permissions** on the export date. The count is historical provenance, **not** a live authorization baseline or license to grant/revoke permissions.
- The actual exported identity, its username, email, passwords, token identifiers and all current production state are deliberately omitted. No direct live production account was accessed, disabled, modified or impersonated. The identity's current channel, activity, overrides, modules, effective navigation and role assignment remain unverified.
- This source row differs from **#2771 / T019R05**, which covers a separate exported account with *two* Store 2 roles (`B2C_STORE_ADMIN` + `RETAIL_CUSTOMER_SUPPORT`) and misleading display-name/store mismatch. Do **not** repeat #2771's inventory write/stock movement test or reuse its historical account as this row.

## Canonical decision and automated evidence

This PR adds only `backend/tests/Feature/V2ExportedRetailStoreAdminRow4AuditTest.php`, with three Laravel Feature tests against disposable test data.

| Scope | Expected current-code result | Evidence |
| --- | --- | --- |
| Role type | Built-in `B2C_STORE_ADMIN` is an active **store** role; `business_scope=store` | Current `BuiltInJobRoleCatalog` + seeded Role model |
| Role assignment | One synthetic Retail store-role row; **no global** roles or inherited global rights | `User::roles`, `storeRoleAssignments`, `hasRole`, inherited permissions |
| Retail authorization | `inventory.view` and `orders.view` restricted to assigned active Retail store | `hasPermission`, `OperationalTenantScope::allowedStoreIds` |
| Other stores | Unassigned Retail store's inventory HTTP and permissions denied | Direct authenticated `admin.b2c.module` negative in AR/EN |
| Wholesale | No B2B/wholesale scope, direct Wholesale inventory HTTP denied | `AdminNavigation::for`, tenant scope and B2B HTTP guard |
| Security and offboarding | Retail store authority cannot become Super Admin, manage roles, or delete users | Negative `roles.manage`, `users.delete` and Security Center direct HTTP |
| Sidebar and languages | User sees B2C channel but not B2B, authorized Retail inventory renders RTL or LTR by locale | Real Dashboard HTML `dir=rtl` / `dir=ltr` checks |
| Forged override | Foreign-store `orders.view` ALLOW plus global role-delete ALLOW cannot create cross-store/platform scope | Persisted synthetic `user_permission_overrides`, effective permission and foreign-store HTTP denial |
| Explicit DENY | A per-user `inventory.view` DENY on assigned store overrides inherited right without mutating role template | Effective permission, tenant scope, direct inventory HTTP |
| Deactivation | Disabled synthetic account loses operational scope/Sidebar projection while preserving identity and role provenance | `OperationalTenantScope`, `AdminNavigation`, database preservation checks |

The test never hard-codes the historical **53** as a current role grant count. Live `config/job_roles.php`, seeded permission-role pivots, module/entitlement and scoped override decisions are authoritative after release. Any historical-count discrepancy requires approved data/source reconciliation, not a mass grant to force equality.

## Impact and scope isolation

- **Backend:** Tests of current User RBAC, tenant scope, built-in role metadata, user-scoped DENY/ALLOW and direct HTTP. No runtime API, auth middleware, policy, permission definition, schema, migrations or seeding changes.
- **Dashboard:** Existing Retail inventory route and channel/Sidebar authority tested in Arabic and English, RTL/LTR. No Blade, navigation or frontend source changes; intended placement is existing Retail Workspace (reuse existing).
- **Customer:** **Not affected** by this test-only PR. No Customer Flutter, login/role, order, checkout, price, credit, notification or Customer API payload changed.
- **Driver:** **Not affected** by this test-only PR. No Driver Flutter, driver token, B2C dispatch, GPS, proof, collection or API contract change. B2C fulfillment remains DRIVER ONLY.
- **Van:** **Not affected** by this test-only PR. No Van Flutter, field route/assignment, wholesale visit, token, settlement or offline contract change. B2B fulfillment remains VAN ONLY.
- No new mobile issue is justified by changes to test/doc paths only. A later *runtime* identity/API remediation must undergo separate Customer/Driver/Van impact analysis and app-specific V2 issues as needed.

## Outstanding source-scope work (Issue remains OPEN)

To finish this **exact account audit**, an explicitly authorized least-privilege reviewer must privately map source Table 5 data row 4 to the current account, then attest its current identity **type** (Dashboard/Customer/Driver/Van), active state, global/store roles, Retail store and tenant ID boundary, current overrides and DENY precedence, entitlements/readiness, effective permission and Sidebar projection. Verify whether the historical 53 inherited permission count remains comparable and whether any cross-channel, sensitive security or finance/SoD anomaly exists. Record **non-identifying** signed evidence on #2909; do not publish the real account identifiers or mutate production data. If remediation is required, use an approved issue-scoped PR and new exact-head CI; never silently assign a new role based on export alone.

Owner #2503 defers final manual browser/staging/UAT to the V2 final gate, but does not waive an unfinished source-account reconciliation. A green synthetic test or merged PR **is not** the historical current-account attestation, so do not close #2909 without it.

## Delivery gate

Single branch `test/2909-v2-exported-retail-store-admin-row4`, one PR base `Foodex_V2`, with exact-head successful Repository Policy, V2 Isolation Gate and all applicable Required CI checks (Laravel full suite, PHP syntax, Pint/PHPStan/security, Arabic/English, MySQL/Redis installation/upgrade/recovery). Keep source identity and production secrets out of commits and workflow logs. Never modify `main`, tag, release, publish APKs or deploy to cPanel/production.
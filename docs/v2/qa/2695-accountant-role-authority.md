# V2 #2695 — ACCOUNTANT role authority (T122R15)

- Integration boundary: `Foodex_V2` only. No main PR, production migration, release or mobile artifact.
- Persona: `ACCOUNTANT` / محاسب. Domain: Finance. Canonical role catalog: `backend/config/job_roles.php`, `BuiltInJobRoleCatalog` and seeded role/permission pivot.
- Placement: **Reuse**, not a new screen. The existing Finance Accountant Workbench (`/admin/erp/finance`) owns user-facing accounting operations; ERP/Finance navigation remains the discoverable entry, subject to the existing server-side role, legal entity and entitlement guards. This PR adds regression evidence only and does not introduce a parallel authorization layer.
- Canonical role grant: `finance.view`, `finance.manage`, `finance.post`, `reports.view`. It expressly lacks `finance.approve`, `reports.export`, `security.view`, `roles.manage`, and `system.update`.
- Effective access is fail-closed when Finance lifecycle is disabled. Role-user assignment is retained for audit and restored only when the lifecycle permits it. An explicit user-level deny of `finance.post` overrides the role's grant without revoking unrelated Finance read permission.
- AR/EN: built-in role presentation is asserted in Arabic (محاسب) and English (Accountant), without new translation keys or visible UI labels. Existing Finance Workbench localization and RTL/LTR remain owned by `FinanceWorkbenchTest`.
- Security and failure evidence: `backend/tests/Feature/V2AccountantRoleAuthorityTest.php` tests canonical permission provenance, disabled/re-enabled finance, negative admin HTTP access, explicit deny priority and persistence of assignment. This does **not** substitute for actual ledger-maker/checker execution checks.
- Scoped privacy: synthetic factory users only, no real account identifiers, credentials or production content. No schema/data mutation outside isolated test database.
- Customer: **not affected** by these test/docs-only changes; no customer app code, API or auth payload changed.
- Driver: **not affected** by these test/docs-only changes; no driver app, dispatch or wallet API changed; B2C=Driver only remains unchanged.
- Van: **not affected** by these test/docs-only changes; no Van app, territory or dispatch API changed; B2B=Van only remains unchanged.
- Manual owner/UAT/staging/browser/device acceptance: **DEFERRED_TO_V2_FINAL** under #2503, not claimed.
- Remaining source-scope before #2695 completion: verify actual Accountant maker/post/checker transaction lifecycle, per-legal-entity and cross-store denial, module entitlement/readiness negative paths, financial SoD audit provenance, and Finance Workbench role-based accessibility and mobile-width RTL/LTR runtime. This bounded regression PR alone is not grounds to close the entire issue.
- CI proof: populate with exact-head Repository Policy, V2 Isolation and Required CI runs after PR creation; no outcome is presumed here.

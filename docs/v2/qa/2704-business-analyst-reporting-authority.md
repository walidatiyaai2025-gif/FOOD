# V2 #2704 — Business / Reporting Analyst (T122R24)

**Source:** FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, T122R24. Parent #2503. Target **Foodex_V2 only**; no main merge, production, release or tags.

## Decision / reuse

The existing canonical built-in \`BUSINESS_ANALYST\` role already lives in \`backend/config/job_roles.php\`, is seeded by \`CoreReferenceSeeder\` via \`BuiltInJobRoleCatalog\`, and has bilingual \`backend/lang/{ar,en}/job_roles.php\` labels. The role is global in the FOODEX RBAC model with **tenant business scope**, analytics domain and only \`reports.view\` plus \`reports.export\`; \`enterprise_bi\` module lifecycle is required for effective grants. It grants **no** orders, finance, treasury, user, configuration, or RBAC mutations. Do not create a second role, Sidebar or report service.

Placement: **Reuse** existing Analytics → Reports (\`reports_center\`) and \`admin.reports.index\`; existing reports/export route \`admin.reports.export\`. Navigation is a projection of server-side effective permissions, not an independent grant. Authorized role assignment is governed by the Security Center; inactive, roleless, module-disabled, and explicit permission-DENY cases fail closed.

## Regression contract

\`backend/tests/Feature/V2BusinessAnalystReportingAuthorityTest.php\` covers:

- Canonical seeded read-only permission bundle, tenant scope, BI requirement and Arabic/English role presentation.
- Existing unavailable role assignment remains recorded for audit but cannot grant read/export or open the real reports HTTP routes; enabling BI restores authorized report navigation, GET and XLSX export; disabling BI revokes them without deleting the assignment.
- AR/EN and RTL/LTR render with existing Dashboard reports page; one canonical Analytics destination and no order, treasury, security or admin Sidebar links.
- Direct orders, security and ERP HTTP requests denied; explicit per-user reporting DENY removes real reports/export access and Sidebar link; roleless/inactive users fail closed.

No seeded production identities, credentials, customer data or existing stores are edited. No new frontend, permission, report, module or API contract is introduced.

## Cross-app classification

- **Customer:** not affected — no customer API, authentication, pricing, order or Flutter code change; business analyst remains Dashboard-only.
- **Driver:** not affected — no dispatch, role, Driver auth, wallet or mobile API change; B2C Driver-only invariant retained.
- **Van:** not affected — no routes, consignment, Van login or mobile API change; B2B Van-only invariant retained.

No mobile follow-up Issue is needed for these test/document-only changes. Any future public reporting API exposure needs separate Customer/Driver/Van compatibility review.

## Acceptance and gates

Required: focused Laravel feature test, PHP syntax/Pint/PHPStan, AR/EN localization, Repository Policy, FOODEX V2 Isolation and exact-head FOODEX Required CI including relevant MySQL/Redis install/upgrade/recovery. Record run URLs and verified head in #2704/PR before merging. Manual V2 staging/UAT remains deferred under parent #2503; no claim of deployment or live-account validation.

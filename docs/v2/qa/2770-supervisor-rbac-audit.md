# V2 #2770 — Customer Service Supervisor RBAC boundary and historical export audit

## Source, owner and privacy

- Atomic requirement: **T019R04**, parent #2503. Historical source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, native Table 5 (zero-based), **data row 11**, also indexed by account-row Issue **#2916**.
- Archived report records one Customer Service Supervisor and **37 distinct inherited permissions** at the time of export. This is not authenticated current-account evidence. No identifying username, email, raw row, password, token or exact user fingerprint belongs in GitHub code, logs or this report.
- Current V2 `config/job_roles.php` declares **36 distinct permissions** for built-in `CUSTOMER_SERVICE_SUPERVISOR`. The historical 37-versus-config 36 observation is **a discrepancy to investigate**, not permission to restore or grant an undocumented privilege. Current production seeded pivots, live role overrides and the archived account's active status have **not** been inspected.
- Existing canonical source: `BuiltInJobRoleCatalog`, `User::hasPermission`, `OperationalTenantScope`, `AdminNavigation` and `CoreReferenceSeeder`; this PR neither creates a new RBAC rule nor changes the built-in Supervisor role.

## Synthetic contract matrix

| Boundary | Automated V2 evidence | Remaining authorized verification |
| --- | --- | --- |
| Role provenance | Seeded active global tenant Supervisor equals current canonical catalog grants exactly; no implicit grant from historic 37-count | Read-only current role/pivot/override comparison for archived account; explain historical difference with approved evidence |
| Least privilege | Positive `orders.view/create/dispatch`, `customers.view` and `whatsapp.customer_service.supervise`; negative roles/users/security administration, finance, `orders.manage/edit/approve`, Driver registry/tracking | Live effective grants, delegated approvals and finance SoD for actual account |
| Wholesale versus Retail | Synthetic Supervisor can read authorized B2B store order scope; cannot inherit Retail store scope or Retail inventory access | Real account's tenant, store, channel and any unusual store assignments |
| Direct URL and Sidebar | AR/EN role profile includes Customer Service entry, excludes Security Admin/Role Access and Retail inventory; direct role-management and Retail HTTP deny | Full isolated V2 browser, keyboard, responsive RTL/LTR and accessibility acceptance in final V2 gate |
| DENY and lifecycle | Explicit global `inventory.view` DENY cancels inherited B2B inventory access and direct URL; deactivation removes operational scope and Sidebar without deleting user or changing built-in role permissions | Current per-user ALLOW/DENY, account lifecycle history, session/token invalidation, manager approval provenance |

`backend/tests/Feature/V2CustomerServiceSupervisorAuthorizationAuditTest.php` provides three integration tests, using synthetic database actors only. The old 37-count is a **historical observation**, not an invariant enforced in code. Future reviewed role-catalog changes must keep the database and test in sync and must preserve least-privilege checks; neither a green test nor any role count proves that the historic real user was audited.

## Implementation placement and impact

- **Backend:** Test-only verification of existing canonical RBAC grants, scope and deactivation. No API, policy, middleware, database migration, seed data or runtime business transition changed.
- **Dashboard:** Existing `Customer Service` profile, server-side navigation and direct Security/Retail endpoints exercised in AR/EN; no UI source or navigation added. `Reuse` existing navigation; no new page, card, tab or link.
- **Customer:** **Not affected by this implementation**. No Customer Flutter sources, Customer API/auth, pricing, credit, order status, notifications or app navigation changed. Existing wholesale order truth stays server-authoritative.
- **Driver:** **Not affected by this implementation**. No Flutter, B2C dispatch, delivery, token, GPS or wallet API change. Supervisor's B2B `orders.dispatch` permission does not introduce B2B Driver fulfillment; **B2B VAN ONLY, B2C DRIVER ONLY**.
- **Van:** **Not affected by this implementation**. No Van Flutter, assignment, route, visits, settlement or offline API change; existing Van-only B2B contract remains.
- No new V2 mobile code Issue is needed for test-only changes. If independent historical/current account inspection discovers actual API/identity/permission changes needed, link the existing Customer/Driver/Van V2 issue and implement in its own disjoint lane.

## Delivery acceptance and outstanding source inspection

- Branch: `test/2770-v2-supervisor-rbac-boundary-audit` from `Foodex_V2`; one issue-scoped implementation PR, exclusively targeting `Foodex_V2`.
- Require exact-current-head Repository Policy, FOODEX V2 Isolation Gate and Required CI success (Laravel tests, syntax, Pint, PHPStan, security audit, AR/EN, MySQL/Redis fresh-install, upgrade, recovery).
- **Do not close #2770 or #2916 solely on the synthetic regression.** Authorized read-only reviewer must match old source account to current status/roles, confirm 37-to-36 provenance, global/store role assignments, ALLOW/DENY, channel/tenant, effective Sidebar and individual SoD outcomes using private evidence. Record non-identifying attestation links without publishing PII. Final manual staging/UAT is deferred under owner #2503, not waived.
- No `main` push, merge, cherry-pick, tags, release, cPanel change, production user mutation, installation or deployment.

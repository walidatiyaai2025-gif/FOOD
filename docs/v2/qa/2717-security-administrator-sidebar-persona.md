# V2 #2717 — Security Administrator Sidebar and HTTP persona (T353R8)

## Source, responsibility and placement

- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` / `T353R8`; linked built-in role definition `SECURITY_ADMINISTRATOR` (#2682); parent #2503. Integration branch is **Foodex_V2 ONLY**.
- Persona: global, active `SECURITY_ADMINISTRATOR`, **not** `SUPER_ADMIN` and **not** the internal `PLATFORM_OWNER`. Active built-in job-role permission catalog owns `security.view`, `roles.manage`, `users.view`, `users.roles.manage`, `users.status.manage`, `security.scopes.manage`, `security.approval_limits.manage`, `security.delegations.manage`, `security.approve`. No retail/wholesale business channel role is implicitly attached.
- Goal: users/roles, role access, security governance and audit, without granting order, stock, platform-wide configuration, financial posting, Customer/Driver/Van execution or cross-store operation.
- **Reuse → existing Dashboard Admin Hub / Security Center** rather than a second role-management UI. Authority: `AdminNavigation::groupsFor` and `AdminSidebarManager::apply` for projected visibility; `admin.administration.index`, `admin.security.index?section=users|roles|governance|audit`, `admin.sidebar-access.roles` and `admin.sidebar-layout.index` for established entry points. The Admin Hub exposes the Security Center only when its real `security.view` Gate allows it. Sidebar/group presentation never grants access by itself.
- Navigation inventory: Administration group → existing Admin Hub (icon/card navigation) → Security Center tabs for Users, Roles, Access/Governance, Audit; canonical role-access editor from the same Administration group. Desktop/mobile-web responsive shell, existing AR/EN translations, RTL/LTR and responsive states are reused. No raw IDs or new business-facing fields, route, form, labels, tooltip or screen.
- Explicit clarification: a global security role may encounter generic shell/ERP entry points; that is **not** a finance/procurement/workflow permission. This check guards the actual privileged operations and prevents claims of a new daily ERP-role identity; unrelated ERP profile UX and final device UAT remain separately tracked.

## Existing RBAC/action authority

The built-in role baseline is authoritative, while `User::hasPermission` enforces per-user `deny` priority and `Gate` guards the Security Center; the `RbacManager` mutation path rejects any requested grant above the actor's effective permission set. Global security roles do not appear in the B2B or B2C channel allowance in `config/admin.php`; store-channel contexts and direct Dashboard B2B/B2C requests remain independently authorized. Financial SoD/maker-checker, module readiness/entitlements, tenant stores and operational journeys remain guarded by their respective existing services and controllers. Platform Owner and Super Admin continue to use separate governance policies and are not implied by this persona.

## Automated acceptance

Additive `backend/tests/Feature/V2SecurityAdministratorSidebarPersonaTest.php` proves:

1. Active `SECURITY_ADMINISTRATOR` has the expected canonical Administration/Sidebar role links, no Super Admin permission, and opens real Admin Hub/Security Center tabs as Arabic RTL with `canSecurity=true` and `canPlatformManage=false`.
2. Existing English LTR Security Center audit and role-access pages open for this role (no new renderer).
3. No `b2b`/`b2c` channel role, business module Sidebar grant, finance/post/approve, order, inventory, update, Driver or Van execute permissions; direct B2B/B2C Dashboard routes reject 403.
4. A delegated security operator attempting to add `platform.manage` beyond own authority gets actual HTTP form validation failure and no unauthorized override.
5. A real explicit per-user `security.view` deny overrides the role grant at HTTP boundary and in Admin Hub `canSecurity`; unrelated `roles.manage` remains retained.
6. Inactive and roleless identities have no visible Admin Sidebar and receive 403 on Security Center.

The real `EnterpriseRbacTest`, `AdminNavigationAuthorizationAuditTest`, role/Permission reconciler and frontend localization validation remain part of exact-head required CI. No live user/account mutations, no schema/data migration. Tests use disposable seeded identities only.

## Independent impact assessment

| Surface | Effect and proof |
|---|---|
| Backend / Management Dashboard | Test-only regression around existing Security Center, role editor, RBAC and Admin Hub. No route, API, database or UI source changes. No hidden link is treated as an authority. |
| Customer | No Customer API/login/session/permission payload, checkout/credit/notifications, Flutter route, biometric or storefront change. Does not add or require a new Customer mobile issue. |
| Driver | No Driver B2C role, assignment, GPS, proof, wallet, token or Flutter navigation change. B2C → DRIVER ONLY unchanged; no linked Driver mobile implementation required. |
| Van | No Van B2B role/login, territory, stock/consignment, route, settlement, token or Flutter navigation change. B2B → VAN ONLY unchanged; no linked Van mobile implementation required. |

## Gates and acceptance

- `backend/`: `php artisan test --filter=V2SecurityAdministratorSidebarPersonaTest`.
- Exact-head GitHub Repository Policy, FOODEX V2 Isolation Gate, backend PHP syntax/Pint/PHPStan/security/OpenAPI, AR/EN, Laravel full suite and MySQL/Redis fresh-install/upgrade/recovery acceptance must succeed before PR merge.
- Link exact commit/run/PR and close this leaf only when complete. **MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL** by parent #2503; no claim of actual owner UX/device/UAT or production runtime acceptance.
- Never target `main`, create a release/tag, or deploy to production.

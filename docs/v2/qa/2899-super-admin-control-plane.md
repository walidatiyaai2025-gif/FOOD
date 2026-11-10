# V2 #2899 — P378 Super Admin is a control-plane role

Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` paragraph P378; parent #2503. **Integration: Foodex_V2 only.**

## Decision and placement

- Domain: Administration / Security Governance; entity: platform administration session; actors: registered Platform Owner and active `SUPER_ADMIN`.
- Goal: a broad security role must not implicitly act as a permanent B2B or B2C operational job title on management login.
- Existing parent: `admin.administration.index` / `/admin/administration` (Administration Hub); `AdminNavigation::defaultWorkspaceUrl` owns the default destination.
- Placement: **Reuse** the authorized Administration Hub. No new screen, Sidebar node, CSS, translation key, permission, API, or entitlement.
- Navigation: `ADMIN_SETTINGS` through the existing Administration business group. The route is server-authorized by existing management middleware; this change does not grant the route.
- Presentation: existing responsive desktop/tablet/mobile web, AR/EN/RTL/LTR, a11y contract and Hub layout unchanged.

## Security scope and behavior

- A valid `SUPER_ADMIN` or registered Platform Owner with an authorized channel lands on the existing Administration Hub on normal login or Enterprise SSO callback, for both wholesale and retail entry points.
- Explicit intended destinations remain subject to the existing `AdminLoginController::authorizedIntendedUrl`, channel checks, tenant context and server authorization; the change neither revokes valid support access nor grants operational permissions.
- Existing `B2B_ADMIN` and `B2C_STORE_ADMIN` daily operational landing paths are unchanged. Neither an inactive user nor an account without an authorized management role can reach the Hub.
- Super Admin is **not assigned** an operational business role, store role, tenant scope or module entitlement by the landing decision.
- This patch is navigation routing only; all financial maker/checker, ERP readiness, tenant/store/channel, module, and dispatch constraints remain enforced by canonical endpoints. Do not infer permission from a Sidebar or from this destination.
- No new audit event is added: existing `management.login.succeeded` records requested/granted channel and method, and existing security session and MFA behavior is preserved.
- No platform account, live production role or permission is modified.

## Acceptance and tests

- `V2SuperAdminControlPlaneBoundaryTest`: AR/EN Super Admin, B2B/B2C default destination, actual authorized Hub HTTP, non-escalated B2B business landing, B2C cross-channel 403, system-update 403 for non-privileged operator, inactive/roleless 403.
- `AdminWebLoginTest::test_super_admin_normal_login_defaults_to_administration_control_plane`: existing real HTTP login no longer redirects Super Admin into an operational B2B dashboard; existing non-super role login tests remain authoritative.
- `V2SuperAdminControlPlaneBoundaryTest::test_super_admin_sso_callback_uses_the_same_governance_landing`: real SSO callback with fake HTTPS identity-provider transport and stored subject mapping; no live credentials or remote calls.
- Existing `AdministrationHubTest` is the runtime AR/EN, RTL, Hub discoverability and Customer/Driver/Van application-card coverage.
- Required: focused PHPUnit, full backend regression, Pint, localization, security and **exact-head required GitHub CI checks**. Do not mark the Issue accepted if any required check fails.

## Cross-app impact classification

| Surface | Impact | Reason |
| --- | --- | --- |
| Backend | Affected | Existing `AdminNavigation` default destination; no API/schema/permission change |
| Dashboard | Affected | Reuses Administration Hub after management login without changing UI |
| Customer Flutter | Not affected | No Customer routes, tokens, authorization, payloads or UI changed |
| Driver Flutter | Not affected | No Driver API, assignment, auth, tracking or dispatch changes |
| Van Flutter | Not affected | No Van API, territory, visits, dispatch or mobile source changes |

No Flutter child Issue is needed for this dashboard-only landing change. B2B=VAN ONLY / B2C=DRIVER ONLY remains unchanged.

## Deployment and release fence

No direct `Foodex_V2` writes, `main` merge, release, tag, APK, Setup/Update ZIP publication or cPanel production deployment. The sole implementation PR is based on `Foodex_V2`. Issue closure requires exact-head CI and integrated acceptance evidence.

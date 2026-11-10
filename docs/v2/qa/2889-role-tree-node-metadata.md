# V2 #2889 — P366 role tree node metadata acceptance

## Authority, placement and ownership

- Source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx / P366; master #2503.
- Domain: Administration → Roles / Page Tree & Permissions. Entity: selected role and registered Sidebar page.
- Actor: a logged-in role administrator with `roles.manage`. No arbitrary owner/account impersonation.
- Goal: inspect a node's human page name, technical page code, registry permission codes, data scope, business channel and associated module **before** adjusting an existing visibility preference.
- Frequency: administrative, occasional. Density: compact per-node grid.
- Placement: **Extend** existing `admin.sidebar-access.roles` renderer (`backend/resources/views/admin/sidebar-role-access.blade.php`). Reuse `AdminSidebarManager::roleMatrix` and `config/admin_sidebar.php`, rather than a new route, copy of permissions or separate security policy.
- Navigation: `ADMIN_SETTINGS` via the existing Administration Hub / Page Tree & Permissions Sidebar item (`sidebar_role_access`). Back path: existing Administration Hub and Edit role permissions link. No additional menu destination.
- Role/scope: `Gate::authorize('roles.manage')` on GET; `roles.manage` on existing PUT. Authorization, store isolation, module entitlements/readiness and actual HTTP access remain server-enforced; showing metadata never grants access.
- UI pattern: existing accessible role picker, grouped role matrix, responsive cards and native checkboxes. Desktop/tablet/mobile share the canonical Dashboard shell; no new persistence or interaction.
- Languages: Arabic RTL and English LTR label catalogs `v2_role_tree` independently translated. Raw page/permission/module identifiers appear only as explicitly requested administrative codes, not as untranslated business labels.
- State: configured/inherited/hidden/global-disabled and RBAC-unavailable states remain the existing `roleMatrix` decisions, unchanged. Missing registry permission/channel/module values explicitly describe missing declarations, **not** global authorization or ERP operational readiness.

## Acceptance checks

1. Render AR RTL and EN LTR over the existing GET route; role administrator sees all six required fields and module labels. Check procurement and retail/wholesale examples sourced from the registry.
2. Restricted actor without `roles.manage`: GET returns 403 and exposes no metadata.
3. Rendering must not mutate role permissions, visibility rows, audit logs or app-level authorization.
4. Existing `AdminSidebarManagementTest`, `V2SidebarGroupPermissionBoundaryTest`, `V2SidebarMetadataTest` and `AdminNavigationAuthorizationAuditTest` remain the authority for actual navigation/RBAC and route behavior.
5. Verify 320/360/390/412/430/768/1024/1280 px, browser zoom, AR/EN and keyboard focus in isolated V2 staging; **NOT YET independently proven by a real browser**. Do not mark final UI acceptance complete without attaching evidence.
6. Exact-head Repository Policy, V2 Isolation Gate and Required CI must succeed before merge. No main/release/production.

## Mobile applicability

- **Customer:** not affected. Only server-rendered admin Blade and private localization/test/docs change; no Customer API, payload, routes, identity, offers, dispatch or checkout.
- **Driver:** not affected. No assignment, B2C delivery, authentication, route, notification or API change.
- **Van:** not affected. No B2B van-only dispatch, stock, route, settlement or API change.
- Responsive Dashboard Mobile Web is **affected** and must pass its separate viewport/keyboard/RTL acceptance; this is not a fourth Flutter app.

## No-go and handoff

Never treat role-tree checkboxes as role permission grants. Do not bypass existing role, tenant/store, finance SoD or module readiness gates. Issue remains open after successful integration if runtime UI evidence is still outstanding. Only `Foodex_V2` may receive the PR; no main promotion, production tag, cPanel install or release.

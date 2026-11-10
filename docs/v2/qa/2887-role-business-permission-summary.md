# V2 P364 / #2887 — role-level aggregated business permission projection

Source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, P364. Parent: #2503.

## Placement and authority
- Domain: RBAC / Administration. Entity: persisted `roles` + `permission_role`. Role: administrator with `roles.manage` for accessing the existing role tree; role mutation stays authorized independently by the canonical SecurityController/RbacManager.
- User goal: inspect a business-domain aggregate for a specific role without mistaking it for a grant of access to an actor.
- Route: existing `admin.sidebar-access.roles?role_id=<role>` (role selection → business permissions → Sidebar Tree), renderer `backend/resources/views/admin/sidebar-role-access.blade.php`, `SidebarManagementController::roleAccess`, and existing protected `AdminSidebarManager::roleMatrix` as parent authority.
- Reuse / Extend: actual source DOCX P364 specifies the **middle column** of the existing Role Selection / Role Permissions / Sidebar Tree workbench. Add a read-only business summary panel between existing Role picker and existing canonical permission/Sidebar Tree editor, not a new route, duplicate RBAC provider, or new Sidebar entry.
- Pattern: role permissions workbench; desktop three columns (role selector, middle business aggregate, existing permission/Sidebar Tree), collapsing vertically at compact desktop/tablet/mobile web. The same server-rendered view supports AR/EN and RTL/LTR, with keyboard-native role picker and checkbox controls.
- Navigation: existing Administration → Sidebar Role Access (`admin.sidebar-access.roles`) linked from Security Roles. Back path remains Security role editor. No new navigation scope or role grants.
- Sources: selected `Role::permissions` eager-loaded via `permission_role` in the protected role workbench controller. Filtering uses explicit business roots with established `security_permissions.groups.*` localized labels and stable counts.
- Role aggregate only shows **recorded** business grants on that role; technical security/settings/user grants are intentionally excluded. It is **not effective user access** and must never be used by APIs, Sidebar, feature flags, entitlement/readiness, overrides or approval workflows. Super Admin implicit authority can exceed recorded role grants.
- The existing security route still calls `Gate::authorize('security.view')`; existing role CRUD remains on `SecurityController` and `RbacManager`, with independent `roles.manage` and assigned-scope guards. No new mutation handler and no changes to `user_permission_overrides` or `permission_role` writes.

## Tests and acceptance
- New `V2RoleBusinessPermissionsAggregateTest`: selected-role scoped (global vs store) permission counts from actual database relations; excludes unrelated privileged codes; no read-side database mutation; dynamic permission changes reflected on next workbench request; RTL Arabic and English LTR; unauthorized workbench HTTP 403.
- Existing `EnterpriseRbacTest`, `AdminSidebarManagementTest` and `AdminNavigationAuthorizationAuditTest` remain regression owners.
- Expected exact-head checks: `php artisan test --filter=V2RoleBusinessPermissionsAggregateTest`; full Laravel tests; repository policy; V2 Isolation; AR/EN localization; MySQL install/upgrade/recovery; `required-ci-gate`.
- Visual semantics: business group names and totals together; text survives color-independent rendering; no icon-only actions and no new buttons. Mobile cards retain native wrap. Role privilege boundary is clearly described in both languages.
- Manual UAT/browser acceptance is deferred to final #2503 / #2750 per owner decision. That does not waive source tests or required CI.

## Customer / Driver / Van impact
- Customer: not affected. No auth API, permission response, mobile route, role assignment or pricing/ordering code changes. Role grants are read and summarized only on the Dashboard.
- Driver: not affected. No Driver API/role changes, dispatch or proof lifecycle change.
- Van: not affected. No Van routing, collection, storage, permission API or APK source changes.
- Backend: a read-only Blade projection of eager-loaded real role permissions. No schema, API contract or authorization mutation.
- Dashboard: affected; existing protected Sidebar Role Access editor receives the middle business-permissions column and localized explanation.
- Production: no main merge, no deployment, release or role mutation.

## Delivery
Branch: `feat/2887-v2-role-aggregate-business-permissions`. Single PR into `Foodex_V2`. Keep Issue OPEN until exact-head required checks pass, changes are merged and proven; do not infer final effective access from a role template.

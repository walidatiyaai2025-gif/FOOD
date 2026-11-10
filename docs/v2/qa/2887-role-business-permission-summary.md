# V2 P364 / #2887 — role-level aggregated business permission projection

Source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, P364. Parent: #2503.

## Placement and authority
- Domain: RBAC / Administration. Entity: persisted `roles` + `permission_role`. Role: administrator with `security.view` for reading and separate `roles.manage` for mutation.
- User goal: inspect a business-domain aggregate for a specific role without mistaking it for a grant of access to an actor.
- Route: existing `admin.security.index?section=roles`, renderer `backend/resources/views/admin/security.blade.php`, SecurityController and existing Security role card as the parent surface.
- Reuse / Extend: role list is already responsive cards with a role search and per-role detail. Add a read-only column/field to each card, not a new route, duplicate RBAC provider, or new Sidebar entry.
- Pattern: Data Explorer / role details; inline aggregate with grouped permission counts. Same AR/EN role card at all responsive widths (320–1280+), with inherited RTL/LTR and existing focus details.
- Navigation: existing Administration → Security → Roles. Back path remains existing Administration Hub. No new navigation scope or role grants.
- Sources: `Role::permissions` eager-loaded via `permission_role`. Filtering groups uses explicit business roots with established `security_permissions.groups.*` localized labels and stable counts.
- Role aggregate only shows **recorded** business grants on that role; technical security/settings/user grants are intentionally excluded. It is **not effective user access** and must never be used by APIs, Sidebar, feature flags, entitlement/readiness, overrides or approval workflows. Super Admin implicit authority can exceed recorded role grants.
- The existing security route still calls `Gate::authorize('security.view')`; existing role CRUD remains on `SecurityController` and `RbacManager`, with independent `roles.manage` and assigned-scope guards. No new mutation handler and no changes to `user_permission_overrides` or `permission_role` writes.

## Tests and acceptance
- New `V2RoleBusinessPermissionsAggregateTest`: role-scoped (global vs store) permission counts from actual database relations; excludes unrelated privileged codes; no read-side database mutation; dynamic add of permission reflected on next request; RTL Arabic and English LTR; unauthorized Security HTTP 403.
- Existing `EnterpriseRbacTest`, `AdminSidebarManagementTest` and `AdminNavigationAuthorizationAuditTest` remain regression owners.
- Expected exact-head checks: `php artisan test --filter=V2RoleBusinessPermissionsAggregateTest`; full Laravel tests; repository policy; V2 Isolation; AR/EN localization; MySQL install/upgrade/recovery; `required-ci-gate`.
- Visual semantics: business group names and totals together; text survives color-independent rendering; no icon-only actions and no new buttons. Mobile cards retain native wrap. Role privilege boundary is clearly described in both languages.
- Manual UAT/browser acceptance is deferred to final #2503 / #2750 per owner decision. That does not waive source tests or required CI.

## Customer / Driver / Van impact
- Customer: not affected. No auth API, permission response, mobile route, role assignment or pricing/ordering code changes. Role grants are read and summarized only on the Dashboard.
- Driver: not affected. No Driver API/role changes, dispatch or proof lifecycle change.
- Van: not affected. No Van routing, collection, storage, permission API or APK source changes.
- Backend: a read-only Blade projection of eager-loaded real role permissions. No schema, API contract or authorization mutation.
- Dashboard: affected; existing Security → Roles card receives aggregated business permissions and localized explanation.
- Production: no main merge, no deployment, release or role mutation.

## Delivery
Branch: `feat/2887-v2-role-aggregate-business-permissions`. Single PR into `Foodex_V2`. Keep Issue OPEN until exact-head required checks pass, changes are merged and proven; do not infer final effective access from a role template.

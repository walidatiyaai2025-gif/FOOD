# V2 P024 — role-display OR is not an authorization decision

Issue: [#2775](https://github.com/walidatiyaai2025-gif/FOOD/issues/2775); source paragraph P024 in `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`; master #2503. Isolated PR base: `Foodex_V2`.

## Existing authority and smallest safe change

**Reuse, do not add a new screen or access-control layer.** `AdminNavigation::groupsFor` constructs the canonical set of authorized routes from real role/permission and operational store/channel/module-readiness decisions. `AdminSidebarManager::apply` receives only those candidates, then processes saved cosmetic visibility/placement. It must never turn a saved `is_visible=true` row into a route grant.

Previously `itemVisibleForRoles` evaluated a multi-role **OR**: any `is_visible=true` restored a page even when another assigned role explicitly hid it, and an unconfigured second role also restored a page explicitly hidden by the first. This allowed presentational defaults to silently override an intentional hide choice. The canonical authorizer still blocked unauthorized HTTP access, but the visible output was misleading and the role-visibility setting was ineffective for multi-role actors.

Use explicit-hide priority for **presentation only**: if an applicable assigned role has saved `is_visible=false`, suppress the page from the already authorized candidate list. Missing rows are neutral, not inferred affirmative grants. If there are no saved hides the authorized candidate remains visible. An explicit show cannot resurrect the suppressed item; a saved show also cannot authorize a route excluded from the incoming canonical projection. Preserve protected navigation items and Platform Owner diagnostic bypass exactly as established by the existing code.

## Actual access and role matrix

- Real authorization: unchanged `AdminNavigation` and Dashboard middleware/controller; `catalog.view` remains required on `admin.catalog.index`.
- B2B_ADMIN + FINANCE global roles: an explicit hidden cosmetic preference for `catalog_management` takes precedence over another role's visible flag or missing row. Neither case changes `catalog.view` or direct HTTP authority.
- With two explicit positive display preferences and a real authorized route, the page remains visible. With no preferences, the canonical page remains visible.
- No-role actor: even with a positive stored preference against a different role, no Catalog navigation or direct HTTP permission appears. Existing cross-store/tenant/channel negative tests and effective permission overrides remain authoritative.
- Module/entitlement/readiness, financial maker/checker and user-level ALLOW/DENY are not interpreted from Sidebar presentation preferences. This change does not edit role records, tenant scope, module readiness or audit history.
- Existing saved `admin_sidebar_role_visibility` records are retained; there is no migration, destructive rewrite, new role permission or changed default source registry.

## Verification

New `backend/tests/Feature/V2SidebarMultiRoleVisibilityPriorityTest.php` verifies mixed true/false, hidden plus unconfigured, true/true, unchanged direct authorized HTTP access, no-role HTTP 403, and Arabic/English execution. Existing `AdminSidebarManagementTest` covers single-role hide, label customization, layout and protected routes. Required exact-head Laravel, localization, repository policy, V2 isolation, MySQL/Redis setup/upgrade/recovery checks must complete before merge.

For Desktop/mobile web, RTL/LTR, accessibility and responsive Sidebar: no markup, layout, focus/ARIA, route, label or screen changes. Existing canonical Dashboard shell and localization remain in place; add no screenshot workflow (retired under UI evidence policy).

## Explicit app impact

- **Customer Flutter:** no API payload, login, catalog visibility, pricing, deep-link or notification change; no mobile issue or rebuild.
- **Driver Flutter:** no API, assignment, dispatch, notification or lifecycle change.
- **Van Flutter:** no API, visit, route, notification or B2B Van-only fulfillment change.
- **Dashboard:** cosmetic role-visibility conflict resolution only; direct route access is unchanged.
- **Backend/API:** no mobile API endpoint or business mutation changes.

## Boundaries and acceptance

Issue #2773 covers separate manager/presentation architecture and is not modified here. Concurrent V2 PRs changing `AdminNavigation.php` own that shared file; this PR must not edit it. Any route projection mismatch must remain linked to its own owning Issue rather than weakened or hidden to make this test pass.

`MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` per master #2503, but all automated and code-scoped acceptance remains mandatory. No main PR, main merge, production release, tag or deployment.

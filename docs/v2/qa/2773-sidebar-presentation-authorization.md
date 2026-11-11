# V2 #2773 / P022 — Sidebar presentation is not authorization

## Source, placement, and scope
- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` P022, parent #2503.
- Integration: `Foodex_V2` only. No `main`, production, release, tag, or cPanel deployment.
- Existing screen: Administration / Sidebar Role Access (`admin.sidebar-access.roles`) and Sidebar Layout (`admin.sidebar-layout.index`). Reuse the canonical `AdminSidebarManager`, `AdminNavigation`, Laravel route/controller Gate checks and persisted appearance settings; no new screen, entry or duplicate permission service.
- Only the issue-scoped server-side regression test is changed. The canonical implementation already separates cosmetics from authorization; no additional production mutation is justified without a demonstrated defect.

## Authority and negative-case contract
1. `AdminNavigation::groupsFor` first computes role/permission, tenant/channel, scope and module readiness eligibility, then calls `AdminSidebarManager::apply` to *suppress or format* that canonical list.
2. `admin_sidebar_role_visibility.is_visible=true` does not create a permission. The role-matrix `base_allowed` check cannot be overridden by a saved display preference.
3. `is_visible=false` and `admin_sidebar_items.is_enabled=false` hide an already-authorized link, but must not change the server's permission decision or `permission_role` rows.
4. A B2B operator cannot acquire B2C customer-group navigation from a cosmetic preference. Direct HTTP scope is independently denied (404 when no authorized B2C store exists).
5. Users without `roles.manage` cannot read or mutate Sidebar security/layout administration by guessing the URL. Protected presentation keys do not grant route access.
6. Database writes in these tests are synthetic local fixtures; no production user, permission or store data is read or changed.

## Acceptance regression
`backend/tests/Feature/V2SidebarPresentationCannotGrantAccessTest.php` exercises real Laravel HTTP and the canonical navigation service:
- permitted B2B customer-group route remains 200 after cosmetic hide, with unchanged permission relationships;
- saved visible notification preference remains denied without `notifications.manage` (Sidebar absent, HTTP 403, role-matrix base_allowed false);
- cosmetic B2C visibility cannot promote a B2B role across channels (HTTP 404 when no authorized store is found);
- global UI-only disabled flag does not revoke a granted route;
- roleless account cannot access or mutate the admin Sidebar editor (HTTP 403, no preference row inserted).

Run `php artisan test --filter=V2SidebarPresentationCannotGrantAccessTest` from `backend` and all protected backend/quality, security, localization, fresh-install/upgrade/recovery required workflows on the **exact PR head**. This document records the assertions, not a claim that CI already succeeded. CI URLs and final SHA belong in the Issue handoff/PR on completion.

## Individual app impact classification
| Surface | Impact | Contract evidence |
| --- | --- | --- |
| Backend / Dashboard | Existing server-side route permissions are unchanged; added synthetic regression coverage only. | The assertions compare effective navigation with direct authorized/forbidden HTTP and unchanged role grants. |
| Customer Flutter | Not affected. | No API, login, status, pricing, ordering or shared mobile contract changed. |
| Driver Flutter | Not affected. | No dispatch, driver route, token, payload or notification changes. |
| Van Flutter | Not affected. | No visit, van routing, assignment, mobile API or notification changes. |

Arabic/English, RTL/LTR, keyboard/focus, responsive design and business-screen labels remain on the existing canonical views; this PR introduces no UI strings or markup. Existing Sidebar regression/locale suites remain required, rather than being replaced by this scoped test. No staging/manual UAT is claimed: it remains deferred under parent #2503. The P022 issue must remain open if any required proof is missing.

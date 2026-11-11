# V2 P383 / #2903 — user assignment and live Sidebar candidate projection

Source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, P383; parent #2503. Integration branch: `Foodex_V2` **only**.

## Placement / route authority
- Domain / entity: Administration → Security & Users → Users → exact selected user
- Actors: authorized Security administrators with `users.roles.manage`; the preview also requires `security.view`. Read-only viewers and roleless principals have no grant ability.
- Intent: assign the user's global/store roles, see the relevant candidate Sidebar tree before submission, and finish by saving audited assignments.
- Placement: **Reuse → Extend** the existing Management modal in `admin.security.index?section=users`; this is not a new Sidebar route or a second permission engine.
- Navigation type: `CONTEXT_ACTION`; the existing visible user row Manage action opens the modal, and the existing Save Assignments submits the exact record.
- Existing server-authoritative mutation: `PUT admin.security.users.roles` → `RbacManager::replaceUserRoles`. Existing read-only transport: `GET admin.security.role-tree-preview`, an explicitly non-navigable internal endpoint with `projection_only=true`.
- Field types: user/store/role are existing role-based lookup/checkbox and scope-select controls (not typed database IDs); no new form fields or free-form privileges.

## P383 gap and issue-scoped change
P077 had introduced a candidate tree endpoint and button but opened the user's Manage modal with no initial preview, and clicking a store-role checkbox left the auxiliary selector on Global. This made the live tree appear unrelated to the last selected store assignment unless operators also changed a second selector manually.

The existing picker now refreshes its candidate tree upon opening the canonical FOODEX Manage dialog. The shared `_brand-components.blade.php` enhancer replaces the original native `<details>` with a labelled button, modal host and accessible dialog (`data-foodex-dialog-open`); the picker observes that real trigger, with native `toggle` capture only as a fallback when enhancement has not run. It automatically switches preview scope to the exact Retail store of the changed store-role checkbox. A changed global checkbox switches it back to Global. Explicit scope selection and Refresh still work. Each form owns its own loading/error/empty state, async generation guard and current selection; textual preview labels use `textContent` (no HTML insertion). The existing role form, backend assignment service and canonical validation stay unchanged.

This preview is **not** an authorization grant nor an exact simulation of target user's effective Sidebar: existing DENY overrides, tenant/channel context, module entitlement/readiness, record ownership and route/action guards still decide access at the server. Display text identifies the candidate nature. A rejected role/store cannot be made effective by a preview.

## Cross-surface impact
| Surface | Classification | Evidence |
|---|---|---|
| Backend / Laravel | Tests only | Existing GET/PUT routes, RBAC engine, audit and permission checks unchanged; targeted HTTP tests exercise real assignment and scoped preview |
| Dashboard | Affected | Existing Security Center Manage modal auto-initializes and follows the exact selected store; AR/EN, RTL/LTR, shared labelled modal trigger + native select controls, accessible status/empty/error retained |
| Customer Flutter | Not affected | No API v1, Customer authentication, navigation, orders, invoices or entitlement payload touched |
| Driver Flutter | Not affected | No API v1, B2C DRIVER dispatch, assignment, wallet or state updates changed |
| Van Flutter | Not affected | No API v1, B2B VAN routing, trips, visits, stock or offline changes |

## Verification and acceptance
`backend/tests/Feature/V2UserAssignmentLiveSidebarCompletionTest.php` validates: localized RTL/LTR user management path, available Manage/role/scope/preview controls and auto-init scope code; real audited PUT of a global and B2C store role then GET global/store candidate trees with unchanged RBAC and audit counts; distinct other-store denial; roleless direct GET/PUT 403; forged B2B store preview 422; global/store mismatch 422; rejected assignment leaves no partial grant.

CI requirements: exact-final-HEAD Repository Policy, FOODEX V2 Isolation Gate, AR/EN, syntax/lint/static analysis, Laravel full suite, real MySQL/Redis install/upgrade/recovery and terminal Required CI Gate. Check outcome recorded in PR/Issue after verified; never infer success. Manual browser/staging/device UAT is deferred to final V2 acceptance per owner comment on #2503; no claim of manual tests. Responsive UI uses existing FOODEX modal, 44px control tokens and bounded tree scroll; no structural layout change.

UI self-audit: Reuse / discoverability / permission boundary / AR+EN / RTL+LTR / keyboard native controls / loading+error+empty / responsive fallback / data truth and safe non-mutating projection are covered by existing primitives and targeted source/HTTP tests. No screenshot required by retired screenshot-evidence contract. Interaction impact is read-only UI and authoritative existing PUT only.

No `main` merge, tag, release, production deployment, cPanel operation or manual staging acceptance authorized.

# V2 #2819 / P083 — Live Sidebar preview versus action authority

## Placement and contract
- Domain: Administration / Security / RBAC; entity: custom role; actor: authorized role manager; user goal: assess *unsaved* Sidebar page-link eligibility without confusing it with action authorization.
- Frequency: while reviewing a role; density: permission code tree; existing parent: Administration → Page Tree & Permissions.
- Placement: **Extend** existing role permission editor rather than new screen, route, Sidebar item or ACL. Canonical GET: `admin.sidebar-access.roles` (`roles.manage`); save remains `admin.security.roles.update` with canonical `RbacManager`.
- Pattern: existing read-only live summary and per-node status. Navigation owner: canonical Admin Sidebar; active context: selected role; desktop/tablet/mobile-web responsive styles unchanged; Arabic RTL and English LTR preserved.
- Permissions: `roles.manage` authorizes preview; it does not imply `users.roles.manage`, business approval, journal posting, order dispatch, a per-user store entitlement or a real route grant.
- States: preview eligible *role-level only*, denied, hidden by preference, module unready and server-authority pending. No submit/publish/approve operation is bound to a preview change.

## Change
The existing P367 role-level preview remains live and read-only. A new visible, AR/EN, screen-reader-associated guidance element clarifies that only **page-link visibility** is being projected, never Approve/Post/Dispatch authority. It is inside the existing preview panel using shared FOODEX styles and has no associated form inputs or API requests. The canonical role-edit Save still requires its own confirmation and server-side RBAC; no second visibility policy or UI approval state is introduced.

## Regression proof
`backend/tests/Feature/V2SidebarPreviewActionSeparationTest.php` verifies both locales, RTL/LTR, live checkbox-change wiring, accessible link between preview and action-authority explanation, no role/visibility/audit writes from preview GET, and direct HTTP 403 for role assignment mutation by an actor authorized to read the preview but lacking `users.roles.manage`. The same actor lacks `orders.dispatch`. Existing P367/P099 tests separately prove module readiness, role grant/revoke, privilege denial, and audit.

## Cross-surface classification
| Surface | Impact |
| --- | --- |
| Backend authorization/API | No runtime route, permission, gate, response schema or database change |
| Dashboard | Existing Page Tree & Permissions preview guidance strengthened only; no new button or business action |
| Customer | No app/API/runtime change |
| Driver | No app/API/runtime change |
| Van | No app/API/runtime change |

B2B=VAN only, B2C=DRIVER only, tenant/store boundary, module-readiness and maker/checker remain server authoritative. No active, stored or pending orders or finance records are changed.

## Acceptance
Run Laravel focused tests, PHP formatting/static validation, AR/EN quality, real MySQL/Redis deployment acceptance, V2 isolation and repository policy on the exact PR head. Browser-device screenshots are optional per `uiux-evidence.md`; manual staging/UAT remains **DEFERRED_TO_V2_FINAL** by #2503, not represented as passed. No merges to main, release tags, APKs, cPanel or production actions.

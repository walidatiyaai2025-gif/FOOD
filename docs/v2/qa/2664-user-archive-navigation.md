# V2 #2664 / P334 — User archive/deletion navigation (bounded increment)

## Source and ownership
- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` / `P334`; parent #2503.
- Isolation: `Foodex_V2` only; no main, tags, cPanel, mobile publishing or live user mutation.
- Reuse the pre-existing `admin.security.index` Security Workbench with its `section=users` and `status=inactive` query filters. Do not add a new route, Sidebar item, CRUD endpoint, or fake Delete button.
- Distinct earlier implementation: #2806 / merged PR #3308 provides safe 3-dot manage/status dialogs. This issue contributes the missing contextual entrance from the Administration Hub to the existing inactive account lifecycle state. It does not repeat that PR.

## Placement and authoritative navigation
- domain: Administration / identity and access governance; entity: User
- role: authorized account lifecycle administrator, plus separate read-only security viewer
- user goal: review inactive user accounts before possible reactivation, without a separate search
- workflow_type: multi-record review; frequency: occasional; data_density: existing paginated user list
- existing_parent: Administration Hub -> Users & Permissions -> Security Workbench
- placement_type: action in existing Administration Hub card (Reuse -> Extend)
- pattern: existing authorization-filtered secondary action and filtered Users tab
- navigation_impact: one extra contextual action; **no new Sidebar destination**
- canonical_route: `admin.security.index?section=users&status=inactive`
- navigation_owner: Administration Hub; navigation_type: DOMAIN_HUB
- permission: `security.view` to see the Security Workbench, **plus** `users.status.manage` to see the lifecycle management shortcut; actual status mutation still independently requires `users.status.manage` at the server
- states: no permission => no shortcut; inactive list empty => existing empty state; no JS state or new mutation
- desktop/tablet/mobile: existing responsive card action and existing Security Users tab; no new layout primitive
- rtl_ltr: existing Blade `dir` and Arabic/English translation key `admin.administration_hub.inactive_users`; accessible link text, existing action target behavior

## Impact matrix
- Backend: read-only `AdministrationHubController` authorization projection; no API/schema/queue/finance or identity policy change.
- Dashboard: additional authority-gated contextual link into existing inactive user filter.
- Customer: not affected; no public API, session, account identity, response contract or Flutter file changed.
- Driver: not affected; no dispatch, role contract, session, account identity or Flutter file changed.
- Van: not affected; no B2B fulfillment, route/visit, role contract, session, account identity or Flutter file changed.

## Automated coverage
`V2UserArchiveNavigationAuthorityTest`: bilingual link + RTL/LTR, target inactive records and existing status dialog; security-view-only role cannot see shortcut or PATCH status, wholesale operator cannot see shortcut or open restricted Users route. Existing #2806/P068 tests cover row actions separately. CI must establish feature test success, Pint/PHPStan, AR/EN, V2 Isolation and exact-head Required CI.

## Critical excluded work and acceptance blockers
**This increment is NOT the irreversible deletion implementation.** P334 contains archive/delete. Safe permanent deletion depends on #2809/P071 (retention, dependency checks and irreversible action), #2810/P072 (self/owner/last-admin/assignments guards), #2813/P075 preflight and the approved audit/recovery contract. Until those are implemented and tested, no delete route, button, silent user row purge or UI claim of complete deletion is authorized. The current filter is for inactive users, **not proof of permanent archive**.

**Keep #2664 OPEN** if the full P334 source acceptance remains unsatisfied even after this bounded PR is green/merged. Future work must continue the same issue/branch/PR under AGENTS.md if possible; otherwise coordinate the remaining dependent atomic issues without creating a replacement duplicate. Manual/browser UAT belongs to master #2503 and #2750, never represented as performed by this work.

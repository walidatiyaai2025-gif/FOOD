# V2 #2760 — T015R02 registered and assigned roles inventory

## Source correction and source scope
The archived 2026-11-09 **FOODEX-Unified-RBAC-ERP-Sidebar-Discussion** page 2, section 2, explicitly reports **48 registered roles, 21 distinct roles used in assignments** (Arabic: `48 دورًا معرّفًا، منها 21 دورًا مستخدمًا في التعيينات الحالية`). The issue title's “21 users” is a transcription mistake; the archive separately records 20 total users and seven without roles. A different metric of 21 distinct users would contradict that archived total and is NOT asserted.

## Placement, authority and data
- Parent #2503, one PR into Foodex_V2 only. This leaf extends the canonical Admin Security Center > Roles & Permissions tab (Reuse → Extend); no new navigation, route, authorization layer, entitlement, migration, release or production update.
- The existing authorized GET `admin.security.index?section=roles` is guarded by `security.view`. Only authenticated `SUPER_ADMIN` receives the raw global source-to-current audit; scoped security-view users receive no report payload and roleless actors are forbidden.
- Existing `RoleAssignmentAudit` returns all **registered** roles, union of distinct role IDs referenced by global `role_user` and store-scoped `user_store_roles`, **unassigned** roles, and active nonarchived assigned roles. A role assigned in both tables counts once; each user is counted separately under `assigned_users`. Preserves inactive and archived assignments as historical evidence but does not treat them as effective grants.
- The underlying existing Roles tab enumerates actual roles, current permission codes/relations, role/store assignment breakdown. User identifier, password, token and sensitive export rows are not disclosed by this new audit. All source baseline figures are labeled historical; live values come from the current database.
- Server-side route/permission/allow-deny/store/channel/tenant/module/entitlement/readiness and finance SoD remain authoritative. No role grant, role reactivation, user status change, audit write, background correction or data migration occurs.

## UI and impact
- UI placement **EXTEND**, Admin Hub → Security Center → Roles & Permissions, existing role report section and responsive card grid. AR/EN translations and RTL/LTR markup, semantic title, descriptive source caveat, no buttons, forms or fake workflow. Empty state and errors handled by existing Security Center.
- Customer: no API/customer identity, commerce, pricing, credit, orders, notifications or routing changes; current Customer app behavior unchanged.
- Driver: no B2C-only dispatch, assignment, wallet or session changes; current Driver app unchanged.
- Van: no B2B-only dispatch, van visits, consignment, offline replay, field sales or session changes; current Van app unchanged.
- No affected mobile APIs or mobile build changes; no new child app Issue is necessary for this read-only Dashboard report.

## Automated acceptance and evidence boundary
- Laravel `V2RegisteredRoleUsageAuditTest` runs seeded database fixtures, adds separate global/store assignment roles and an unused role, checks live current totals and distinct-union semantics, confirms distinct assigned users separately, historical inactive role preservation, no read-time changes, AR/EN, RTL/LTR and HTTP 403/delegated non-disclosure.
- Keep #2503 and final manual UAT gates open. Owner #2503 decision defers browser/device/staging UAT to V2 final, but mandates exact-last-head Required CI and merged PR for completed leaf closure.
- Original archived numerical aggregate is verified, but full row-by-row archived role IDs/current production identities are not provided, nor claimed identical. No production DB accessed. A later dedicated forensic audit may be required if owner requests historical membership verification beyond the report's purpose.

# V2 #2806 / P068 — Authorized Users row-action entry

## Placement and contract

- Source: Unified RBAC/ERP/Sidebar discussion P068. Parent #2503. Integration: **Foodex_V2 only**.
- Domain: Administration / Identity & Access; entity: a real managed User; actor: an authorized Security Center administrator.
- User goal: choose the exact account's access-management or status action without multiple full-width grid buttons.
- Existing parent and canonical route: `admin.security.index?section=users`; `SecurityController::index`; `admin.security.users.status` and existing role/permission route family.
- Placement: **Reuse / extend** the existing Security Center Users table, type **CONTEXT_ACTION**. No extra sidebar destination, screen, controller, API, permission or duplicate role management.
- Pattern: standard shared `foodex-ops-actions` + `foodex-ops-menu` row-action disclosure, with existing `data-foodex-operational-modal` accessible exact-user dialogs.
- Permissions: server-side `users.roles.manage` controls Manage; `users.status.manage` controls Deactivate/Reactivate. Menu appears only if at least one ability allows an action. All direct POST/PATCH requests retain their Gate, MFA, owner/self/last Super Admin, type-confirmation, account-scope, token-revocation and audit guards.
- Client interaction: after the shared dialog enhancer runs, the menu opens the exact row's existing dialog host; no new mutation route or optimistic success state. No-JavaScript fallback leaves native dialog action controls intact. Script-generated menu is hidden until matching modal triggers are found.
- Desktop, tablet, mobile web: shared 44px green ellipsis, portaled menu avoids table scrolling overflow, logical inline positioning supports AR/RTL and EN/LTR; native keyboard summary and shared modal focus handling retained. No screenshot assertion is a substitute for real runtime verification.
- User inputs: reason and typed account email are legitimate typed confirmation, not raw internal database IDs; role/store selection remains the authoritative picker.

## Implemented increment

- User row's authorized Manage and Deactivate/Reactivate are discoverable under one shared 3-dot menu, rather than separate always-visible buttons.
- Existing status transition already provides lossless deactivation/soft archive with identity, roles, finance and sessions preserved; the UI does **not** silently equate that reversible operation with destructive permanent deletion.
- `V2UserLifecycleRowActionsTest` exercises the authorized row/menu in AR/EN, inactive-state reactivation label and direct-HTTP denial for a non-security operator. Existing `V2UserSoftArchiveRetentionTest`, `V2UserDeactivationRevocationTest`, and `V2ProtectedUserLifecycleTest` cover changes/revocation/owner/last-admin guards.

## Customer / Driver / Van classification

- Customer: no Flutter, API payload or route change. Existing account deactivation revokes Customer tokens; preserved.
- Driver: no Flutter, assignment, delivery or session schema change. Existing deactivation revokes Driver tokens; B2C DRIVER ONLY retained.
- Van: no Flutter, B2B route/trip/session schema change. Existing deactivation revokes Van tokens; B2B VAN ONLY retained.
- No app-specific mobile Issue needed for this Dashboard presentation-only change.

## Outstanding P068 source acceptance — do not close Issue

Permanent deletion is **not enabled** by this PR. P071/#2809 dependency requires documented linkage/financial retention checking, and P072/#2810 + P075/#2813 guard protected identities and 7 account categories before permitting irreversible action. These boundaries are not replaceable with a cosmetic Delete button. A distinct archival lifecycle from ordinary deactivation also requires approved semantics and server state (the current reversible P070 status archive is the only canonical archival behavior).

Full P068 is not accepted until the separate Archive and conditional Permanent Delete capabilities are implemented, security/retention tested, and linked to this row menu; and real responsive/keyboard/RTL/LTR runtime is verified. The open parent #2503 and #2750 final cross-persona UAT remain unchanged. No main, release, tag, ZIP or production claims.

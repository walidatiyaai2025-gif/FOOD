# FOODEX V2 #2920 — historical account-role audit, Table 5 row 15

## Provenance, privacy, and scope

- Parent: #2503. Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, **native Table 5, data row 15**.
- The historical export recorded exactly one inherited capability associated with global `B2B_DRIVER`. That is a **point-in-time RBAC snapshot**, not proof of the current account type, activity, actual app use or authorization.
- Never place the account's login, user ID, email, phone, credentials, token or current personal data in GitHub. No real account was read, changed, disabled, archived, deleted or given a role.
- All code validation uses disposable synthetic Laravel users, drivers and Van assignments. This Issue is **not yet accepted** until separately authorized, confidential read-only historical-to-current reconciliation.

## Actual finding and remediation

Canonical sources:
- `backend/config/job_roles.php` grants legacy `B2B_DRIVER` only `deliveries.b2b.execute`.
- `User::hasPermission` correctly denies grants on inactive identities, but `VanRuntimeContextResolver::canUseRuntime` previously fell back to `hasRole('B2B_DRIVER')` + active Driver/Van assignment **without independently checking `User.is_active`**. Consequently, an inactive legacy driver retaining its RBAC assignment and an effective Van assignment could satisfy the resolver's compatibility bridge. Whether an outer auth middleware prevents all such requests is a separate layer and must not be used to justify a permissive resolver.
- Updated `canUseRuntime` to reject inactive identities before either explicit `van.login` or legacy driver-role compatibility checks. **CI compatibility finding:** `PushDeliveryService` intentionally hydrates only `users.id` and `users.locale` for notification recipients; treating absent `is_active` as `false` would silently deny valid Van push notifications. The resolver therefore fetches authoritative persisted `is_active` **only when the User model omits it**, denying missing/deactivated rows and retaining the active legacy compatibility path. The canonical push-dispatch query now eagerly selects `user:id,locale,is_active` to avoid one extra active-state lookup per Van notification recipient; fallback remains available for other safe partial User projections. No role/permission/tenant scope was widened, no actual account was changed.
- The new regression `backend/tests/Feature/V2ExportedAccountRow15AuthorizationTest.php` first proves a *synthetic active* B2B legacy driver can use only a real effective Van assignment, then disables that identity while deliberately preserving its historic role and assignment. It asserts fail-closed resolver and **HTTP 401 for disabled accounts** at Van visits, Van push-device registration (no device token persisted), and Driver heartbeat, in AR/EN. The outer canonical `auth:sanctum, active.user` middleware revokes disabled sessions with 401 before the Van-specific 403 authorization boundary. A separate regression verifies expired Van assignments still return **403** for active identities. A third regression confirms a deliberately *partial user projection* (id + locale, as loaded by Van push notification delivery) resolves the stored account status for both active and disabled identities.

## Authority and app impact matrix

| Surface | Classification |
| --- | --- |
| Backend | **Affected**: fixes dormant legacy-role fallback under `VanRuntimeContextResolver`; authorized B2B Driver profile still requires an effective matching Van assignment; explicit `van.login` remains scoped. |
| Dashboard | **Not changed**: no Dashboard navigation, view, user lifecycle or role editor change. Existing direct RBAC protections remain authoritative. |
| Customer | **Not affected**: no customer session/API/order/catalog flow. |
| Driver | **No Driver-App contract change**: B2C Driver remains the only Driver delivery runtime. Synthetic negative heartbeat check included. |
| Van | **Authorization contract hardened**: an inactive B2B legacy-driver identity must not be authorized even if an old valid Van assignment remains (HTTP 401 from active-user middleware; direct resolver denial). Existing Van runtime receives normal permission denial; source coverage linked to existing Van V2 auth issue #2738 and cross-app contract #2740. No new mobile endpoint/payload or Flutter screen. |

## Remaining confidential acceptance

Before closing #2920, a separately authorized reviewer must inspect the *specific historic account* through a least-privilege, read-only path and privately confirm: identity type (Dashboard/Customer/Driver/Van), active status, associated Driver/Van records, current global/store roles, permission overrides, scope, runtime assignments, module/entitlement, channel and Sidebar/HTTP projection. Record conclusions without secrets or identifying details. Never infer a production change from the archival role alone. If actual Van App behavior requires a Flutter update, use #2738 (or a distinct scoped Van subissue if necessary), with synchronized full tests.

**Delivery acceptance:** preserve original #2920 branch/PR, exact-head Repository Policy + V2 Isolation + Required CI including affected Laravel HTTP tests, AR/EN, security and MySQL install/upgrade. An approved PR targets `Foodex_V2` **only**. Do not close this source-specific Issue or describe confidential account reconciliation as verified merely because synthetic regressions pass.

**Prohibited:** V2→main merges, production data mutation, deployment, releases, tags, or public disclosure of archived account identifiers.

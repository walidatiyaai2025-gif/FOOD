# V2 #2808 / P070 — Lossless managed user soft archive

**Parent:** #2503. **Integration boundary:** `Foodex_V2` only; no `main`, production, release, or promotion.

## Source intent and existing canonical implementation

P070 requires archiving/soft-deleting an identity **without deleting the account, roles, relationships, or audit evidence**. The existing canonical surface is the authorized Administration → Security Center → Users status action (`PATCH /admin/security/users/{user}/status`), backed by `SecurityController::updateUserStatus` and `RbacManager::setUserActive`. It already changes `is_active`, `deactivated_at`, and a reason; retains the stable user ID, email/password, global and store-role assignments, and historical records; revokes API tokens, remember-me credentials, and dashboard security sessions; and records an audit event. Restoring the same identity does not reinstate revoked credentials. There is deliberately **no destructive delete** and no second implementation of RBAC authorization.

## Scope verified by this issue

- **Backend/HTTP:** Real `users.status.manage` authorization and direct-HTTP 403 for a store-scoped actor. A global Super Admin may deactivate/re-activate a non-protected user through the existing route. Protected owner, self, and last Super Admin checks remain in the canonical manager and preexisting `V2ProtectedUserLifecycleTest`.
- **Database/retention:** `V2UserSoftArchiveRetentionTest` checks the exact stable identity, hashed password, global `role_user`, store `user_store_roles`, absence of destructive deletes, audit entry, and preservation after restoration. Existing `V2UserDeactivationRevocationTest` additionally asserts token/session revocation, inactive web session denial and protected authorization.
- **Dashboard:** Reuses the existing Users panel, AR/EN labels, RTL/LTR styling, modal confirmation and status badges. No new navigation item, form or UI route; no layout changes or new text keys. Existing `deactivate` and `activate` actions remain discoverable through the authorized security interface.
- **Security/finance/tenant:** This action is an identity control-plane status transition. No ledger, invoices, inventory, store ownership, role permissions or finance table is modified. A store-scoped manager cannot act as a global identity administrator. Tests intentionally do not fabricate live financial records or claim that every operational edge has been validated against production.
- **Customer app:** Existing global account/session invalidation causes prior customer tokens to be revoked on deactivation. No changed API shape, UI path or checkout/price logic; client application source unchanged.
- **Driver app:** Existing token invalidation applies to Driver sessions; no changes to B2C-only dispatch, delivery states or Driver API payload.
- **Van app:** Existing token invalidation applies to Van sessions; no changes to B2B-only fulfillment, stock, route or visit payload.
- **Localization/a11y:** Existing AR/EN labels and responsive modal already present; this issue introduces no new screen. Runtime/browser/device UAT remains at final V2 gate #2750 under #2503.

## Reproducible acceptance

From `backend/`: `php artisan test --filter=V2UserSoftArchiveRetentionTest`, then existing `V2UserDeactivationRevocationTest` and `V2ProtectedUserLifecycleTest`; verify all exact-head GitHub Required CI child jobs, including Pint, PHPStan, backend full tests, AR/EN, policy, V2 isolation and MySQL/Redis install/upgrade/recovery. Do not merge until final-head checks are all green and target ref is still `Foodex_V2`.

**Provenance limit:** This is source-code and synthetic database acceptance, not an assertion that production identity/finance records have been accessed, restored, or modified. No actual account is archived by these tests.

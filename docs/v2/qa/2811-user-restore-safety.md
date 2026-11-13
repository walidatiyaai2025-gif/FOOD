# V2 #2811 — P073 archive and restore review contract

Integration: `Foodex_V2` only. Parent: #2503. Source: P073, with P070 soft archive already merged through #2808 / PR #3127.

## Canonical lifecycle / UI placement

Reuse the existing **Administration → Security → Users** records and `admin.security.users.status` + `/api/v1/admin/security/users/{user}/status`. No new Sidebar/DELETE route or duplicate lifecycle service. Restoring an archived (inactive) user is the existing reactivation transition, not a new identity or replacement key.

For every actual status transition, `RbacManager::setUserActive` refuses the change until a nonempty reason of at least 8 characters, explicit impact-review attestation, and a second account-specific confirmation matching the target's email are submitted. The Dashboard provides an operational impact summary before the confirmation fields and retains the existing final modal confirmation. API clients must submit the same attestations; missing inputs fail closed with validation errors. Both transitions audit the operator and the change reason; a restoration clears the deactivation reason on the live account but retains its restoration reason in immutable audit history.

**Impact presented:** revocation of credentials/sessions on archival; preservation of user ID, roles, store assignments and financial references; restoration re-enables sign-in but never restores revoked tokens. Counts are the existing authoritative role relationships, not invented source account data.

## Safety and role boundaries

Authorization remains `users.status.manage`, including direct HTTP enforcement; `RbacManager` still rejects Platform Owner status mutation, self-deactivation and removal of the last active Super Admin. No physical deletion, cascade, production account mutation, permission escalation, or finance change. P071 hard delete/retention (#2809) and P072 protected assignments (#2810) remain separate blocked scopes. The current role/store authority and underlying admin gate remain authoritative; this change never grants access to Retail users or bypasses B2B/Retail segregation.

## Impact matrix

- **Dashboard:** modified existing Users status action with bilingual reason, impact and explicit confirmation; existing navigation, responsive modal, AR/EN and RTL/LTR retained.
- **Backend/API:** same status route and JSON payload; transitions now require `reason`, `impact_reviewed`, `confirm_email`. No new response fields or read access.
- **Customer:** no Flutter source/contract change; rejected administrative status mutations do not grant customer auth privileges.
- **Driver:** no Flutter source/contract change; existing session invalidation/assignment isolation preserved.
- **Van:** no Flutter source/contract change; B2B Van-only authorization and session invalidation preserved.

## Automated checks / acceptance

`V2UserRestoreSafetyTest` checks missing reason, missing impact attestation, wrong email, allowed archive/restore, server API fail-closed behavior, audit trail. Existing `V2UserSoftArchiveRetentionTest` is adapted to the new attestation to preserve identity, roles, sessions and anti-cross-store regression. Run required exact-head Laravel syntax/Pint/PHPStan, full tests, AR/EN, V2 isolation, repository policy, MySQL/Redis and terminal Required CI, and inspect every required child before merging.

This PR does not assert independent manual browser/device staging acceptance, future hard-deletion behavior or full P071/P072 source implementation. No main, release, tag, staging or production publication.

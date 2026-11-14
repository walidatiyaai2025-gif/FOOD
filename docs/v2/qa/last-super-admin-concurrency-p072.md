# V2 P072 — serialize last Super Admin offboarding (#3452)

**Source:** P072/#2810 protected-user lifecycle; parent #2503. **Integration is `Foodex_V2` only.** No main, tag, release, production or cPanel deployment.

## Reproduced gap and contract

Prior to this change, `RbacManager::setUserActive` and `replaceUserRoles` both called `isLastActiveSuperAdmin` before opening the mutation transaction. Separate concurrent requests against two elevated accounts could both see a count of two and then independently remove the active status/role, leaving zero effective active SUPER_ADMIN identities.

Preserve the existing fast rejection for standalone requests and repeat the authoritative check **after** acquiring the same persistent `roles.code=SUPER_ADMIN` row lock **inside** each mutation transaction. Use `lockForUpdate()` for MySQL/MariaDB; SQLite provides serial test writes but does not emit a FOR UPDATE clause. The locked critical section includes refreshing the target and counting effective active SUPER_ADMIN users immediately before the write. The same row is locked for all deactivations and role-sync requests that omit SUPER_ADMIN, including a target that gained SUPER_ADMIN after an early stale read.

No elevated role is granted automatically. The pre-existing self-deactivation, registered Platform Owner, open Driver/Van assignment, typed-confirmation, credential invalidation, audit and permission checks remain in place. A rejected last-admin mutation does not change the target, revoke tokens or commit an audit event. Inactive or absent canonical role rows cannot be used to manufacture a new elevated account; no fail-open path is introduced.

## Test evidence / fail-closed scenarios

`backend/tests/Feature/V2LastSuperAdminMutationSerializationTest.php` exercises:

1. Deterministic time-of-check/time-of-use interleaving: after the preflight database count, an independent account becomes inactive; the protected target must remain active and the localized AR/EN API response must deny the attempted deactivation.
2. The same stale-preflight interleaving for the roles HTTP endpoint; the last elevated target keeps SUPER_ADMIN, no success audit is committed.
3. Authorized non-last status change and role demotion still succeed and record their audits, leaving one active elevated identity.
4. On MySQL/MariaDB, the query listener checks a `FOR UPDATE` against the canonical role row while the transaction is active for **both** mutation paths. SQLite runs the behavioral tests; the MySQL-specific SQL assertion is skipped there.

The interleaving tests force a mutation after the **first** count in a single test process; they are not a substitute for an independently scheduled two-connection MySQL stress test. The real MySQL CI job and exact-final-head required checks are mandatory before integration.

## Impact and scope ownership

- **Backend/API:** Existing Admin Security status endpoint and roles management endpoint now serialize last-admin checks; signatures, permissions, payloads and response error keys remain unchanged.
- **Dashboard:** Existing Security → Users actions continue using the same routes, controls and accessible AR/EN/RTL/LTR UI. No new screen/navigation, AR/EN string or DOM change.
- **Customer:** NOT AFFECTED — no customer Flutter/API/checkout/identity contract modified.
- **Driver:** NOT AFFECTED — no Driver Flutter/API/dispatch contract modified; B2C remains DRIVER ONLY.
- **Van:** NOT AFFECTED — no Van Flutter/API/assignment contract modified; B2B remains VAN ONLY.
- **Files:** `backend/app/Services/RbacManager.php`; new feature test; this issue-scoped QA document. Concurrent Installer, Updater, mobile and CI owners are not touched.

## Completion gate

- [ ] Focused Laravel test and existing user lifecycle/RBAC regressions pass
- [ ] PHP syntax, Pint, PHPStan/security and AR/EN checks pass
- [ ] Required CI Gate, Repository Policy and FOODEX V2 Isolation Gate all SUCCESS on the **exact final head**, including MySQL/Redis acceptance when selected
- [ ] PR base verified as `Foodex_V2`; final file fence and concurrent worker ownership rechecked; reviewed SHA-guarded merge into `Foodex_V2` only
- [ ] Verified merge SHA and issue-specific close for #3452; preserve parent #2810 and master #2503 OPEN until their wider source acceptance

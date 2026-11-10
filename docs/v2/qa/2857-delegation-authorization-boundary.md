# V2 P134 — Temporary delegation never bypasses user DENY or deactivation

Issue #2857 · Source `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` paragraph P134 · Parent #2503 · **Foodex_V2 only**

## Ownership, domain, route and authority

- **Domain:** Enterprise identity / security governance; **actor/persona:** security administrator with `security.delegations.manage` who requests a temporary permission delegation, a separate approved checker, an active delegator, and a designated delegate. Existing `B2B_ADMIN` uses ordinary wholesale permissions but cannot administer security delegation without the required separate permission.
- **Entry point:** existing Administration Hub → Enterprise Access & Delegations; canonical UI and POST/DELETE routes `admin.enterprise-security.delegations.store` and `admin.enterprise-security.delegations.destroy`. Under Reuse → Extend, retain existing workflows, Laravel Gate, EnterpriseSecurityManager request/approval, EnterpriseSecurityAccessResolver and audit history. No separate screen, new Sidebar item or permission grant.
- **Data:** `enterprise_delegations` timestamps, scope_key, permission code, delegator/delegate identities; `user_permission_overrides` global or store-scoped DENY. Shared role permissions are immutable during this change. No database migration or live record mutation.
- **Guard:** direct acting authorization is unchanged; an effective temporary delegation is evaluated only when the actor is active, the grant is live and unrevoked, the requested scope matches, the granting user remains active and independently holds the permission within its role scope/limits, and **the receiving user has no explicit DENY for that permission and requested scope**. The check reuses `User::permissionOverrideEffects` precedence; no new special "delegation override" ALLOW, and no relaxing Super Admin direct access behavior. Finance maker/checker remains independently authoritative in the existing workflow — a delegation cannot become its own checker approval or a ledger mutation.

## Finding and fix

Before #2857, the shared resolver first checked `directAllows(delegate)` but if that failed on a user DENY, it evaluated `directAllows(delegator)` and returned true. This resurrected an explicitly denied ability. It also did not recheck `delegator.is_active` after an already-approved grant was issued, so a disabled delegator could confer authority until the grant's expiry.

Now the resolver checks the delegate's **effective explicit DENY** before considering delegation rows and requires the eager-loaded delegator to exist and remain active. Both checks occur at read time, so status changes take effect without revoking/deleting historical records or running a migration. An unrelated store-specific DENY is scoped to its own store and must not blanket-deny another authorized store; global DENY applies to all requested scopes. Existing time-window, revocation, permission, approval limit, organization/store and workflow SoD checks remain intact.

## Acceptance matrix / negative paths

`backend/tests/Feature/V2EnterpriseDelegationDenyBoundaryTest.php` tests:

1. Approved-style unexpired delegated `orders.approve` is initially available to a permissionless active recipient; adding `global` DENY removes it in AR and EN. Removing the DENY restores it, but explicit revocation again removes authority, without modifying the grant's audit/provenance.
2. Two wholesale B2B stores with a platform-scoped delegation; a store-specific DENY blocks exactly the denied store, while a different B2B store continues to be authorized, AR/EN.
3. Deactivating the delegator stops authority immediately without deleting `enterprise_delegations`; reactivation restores a still-valid grant; deactivating the delegate or expiring the time window also blocks access.
4. Direct POST/DELETE requests from an unauthorized B2B operator return 403, do not create another grant and cannot revoke one, in both locales.
5. Existing canonical `EnterpriseSecurityAdministrationTest` remains responsible for privileged Maker → independent Checker approval, approval-limit enforcement, role data scopes and expiring grants; full CI runs all those suites. No assertion here confuses programmatically created test rows with bypassing real HTTP approval, and no browser acceptance is invented.

## Impact by surface

| Surface | Classification | Evidence / next steps |
| --- | --- | --- |
| Laravel Backend | **Affected** | EnterpriseSecurityAccessResolver authorization now denies stale delegated permissions before any protected workflow action |
| Dashboard | **Affected, no UI contract change** | Existing Enterprise Access & Delegations authorized form and endpoints remain; expired/denied/inactive grants fail server-side even if previously shown in a list |
| Customer Flutter | **Not affected** | No Customer app Dart, Customer finance or purchase API payload, permission model, login, route or deep-link contract changed |
| Driver Flutter | **Not affected** | No Driver status/delivery/dispatch, wallet API or Dart contract change |
| Van Flutter | **Not affected** | No Van-only fulfilment, route/collection, visiting API or Dart contract change |

If a later change exposes this delegated-enterprise-authority resolver to any mobile app contract, open a dedicated V2 MOBILE issue per impacted app before integrating that change. No claims of Customer/Driver/Van runtime tests where unchanged-path CI skips them.

## Testing and delivery gates

Require exact-head Repository Policy, V2 Isolation, Localization AR/EN, syntax/Pint/PHPStan/Composer audit, full Laravel tests including real HTTP 403/negative scope and maker-checker suites, MySQL/Redis real fresh-install/upgrade/recovery acceptance, and `required-ci-gate`. Record actual run URLs/SHA in Issue #2857, and retain Issue OPEN for independent isolated browser AR/EN responsive and accessibility evidence where required; automated green CI alone cannot prove browser UI accessibility. One owned branch and one PR, exclusively base `Foodex_V2`. No `main` merge, production, tag, deployment or release ZIP.

# V2 #2810 / P072 — protected user offboarding: open operational work

**Integration boundary:** `Foodex_V2` only. Source P072, parent #2503. This PR changes the existing canonical, audited account status/archive transition only. No production accounts are touched.

## Existing authority retained

- The canonical `PATCH /api/v1/admin/security/users/{user}/status` and Dashboard equivalent enforce `users.status.manage` on the server.
- `RbacManager::setUserActive` already denies self-deactivation, registered Platform Owner deactivation and last-active `SUPER_ADMIN` deactivation. The P073 server-side reason, impact acknowledgement and typed email, token/session revocation, audit and transaction remain intact.
- No administrative permanent-delete endpoint is introduced. The distinct P071/#2809 retention and irreversible deletion workflow is still OPEN and blocked from being claimed complete.

## New P072 operational-custody gate

Before a currently active account may be deactivated, a server-side guard checks:

1. Unfinished Driver delivery assignments belonging to the user (via `drivers.user_id` and `driver_assignments`, ignoring terminal states and completed assignments).
2. Effective active Van assignments where the user is the representative or the assigned Driver (including `van_assignments.effective_until`).
3. Van visits initiated by that user in `planned` or `started` state that are not closed.

If any is open, the existing API returns localized HTTP 422 on `is_active` and does not revoke credentials, mutate the account, remove assignments or create a status-change audit event. Work must be completed or reassigned using its authorized operational workflow; no automatic reassignment or destructive cascading operation is added. Closed/completed historical records remain visible for audits and do not unnecessarily block archive.

## Source and acceptance evidence

- Code: `backend/app/Services/RbacManager.php`, localized messages in `backend/lang/{ar,en}/admin.php`.
- Regression: `backend/tests/Feature/V2UserOpenAssignmentOffboardingTest.php` checks pending Driver work, representative/Driver Van custody, live Van visit and Arabic/English error text, completed historic work accepted, unauthorized user direct-HTTP denial, token preservation and audit behavior.
- Focused run: `php artisan test --filter=V2UserOpenAssignmentOffboardingTest`; required gate remains the exact final PR SHA, including backend Laravel/security/Pint/PHPStan, localization, V2 Isolation and Repository Policy. Do not merge while any required check is missing/red.

## App impact / release boundaries

| Surface | Classification | Evidence |
| --- | --- | --- |
| Dashboard | **Affected** | Existing security user status flow now fails closed if outstanding Driver/Van work exists; existing form displays the server validation result. No new route, Sidebar menu or permissions. |
| Customer Flutter | **Not affected** | No Customer-facing API/controller/response or Flutter files changed. |
| Driver Flutter | **Not affected** | Existing Driver assignment lifecycle untouched; only administrators' account-offboarding status mutation is guarded. |
| Van Flutter | **Not affected** | Existing Van runtime endpoints and assignment lifecycle untouched; administrator archive rejects active Van custody. |

Full P072 completion also requires coordination with P071/#2809 permanent deletion / retained financial references and independent final V2 acceptance; do not close #2810 solely on this bounded code integration. **No main, tags, release, cPanel or production deployment.**

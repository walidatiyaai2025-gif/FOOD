# P130 — Platform control-plane identities are not daily operational roles

**Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, requirement `P130`, parent #2503 / leaf #2854.
**Owner isolation:** `Foodex_V2` only; no PR to `main`, deploy, release or tag.

## Governing decision

`SUPER_ADMIN` and the internally registered `PLATFORM_OWNER` are platform **control-plane** identities. They must not be assigned an ordinary Wholesale/Retail operational home just because their role has wide authorization. Ordinary day-to-day work uses separate job roles scoped to wholesale or to an exact Retail store. This change closes the `/admin` landing loophole: that management entry now redirects privileged sessions to the established **Administration Hub** instead of rendering the generic dashboard shell. The existing password/SSO login `AdminNavigation::defaultWorkspaceUrl()` already routes those identities to the same hub.

This is **not** an unconditional authorization ban on all privileged administrative inspection and exception-handling routes: control-plane accounts retain explicitly authorized deep links, subject to the existing finance/SoD, module and tenancy guards. In particular Retail support still needs the explicit `store_id` and `support_access=1`; simply owning a wide global role must not remove the store-context check. Such exceptional use should not become an auto-selected default work area.

## Exact behavior / route matrix

| Principal | `GET /admin` | Explicit platform hub | Explicit operational access |
| --- | --- | --- | --- |
| SUPER_ADMIN | Redirect `admin.administration.index` | Allowed | Wholesale inspected by direct authorized request; Retail support requires explicit selected store + support context |
| SUPER_ADMIN + CUSTOMER_SERVICE_MANAGER | Same control-plane redirect | Allowed | Customer Service role cannot override control-plane default |
| Registry-backed PLATFORM_OWNER | Same control-plane redirect | Allowed | Same explicit context and financial hard constraints |
| Fake/unregistered PLATFORM_OWNER database assignment | Does not grant owner or SUPER_ADMIN identity | No elevation | No special context bypass |
| B2B_ADMIN | Existing operational shell | Existing entitlement/permission-based hub cards | Existing Wholesale permissions |

The landing route is *navigation*, not a new permission grant. `User::isPlatformOwner()` still requires the protected owner registry; `User::hasRole('SUPER_ADMIN')` interprets genuine owner membership. The change does not introduce job roles, mutate role assignments, bypass the module entitlement resolver, change cost/credit/SoD rules, or permit use of the B2C API without a store.

## Acceptance / tests

`backend/tests/Feature/V2ControlPlaneDefaultLandingTest.php` creates a disposable seeded database and verifies:
- AR/EN RTL/LTR; `/admin` redirects to canonical hub for SUPER_ADMIN, multi-role SUPER_ADMIN + customer service, and internally provisioned PLATFORM_OWNER; the actual hub renders successfully
- Existing explicit `/admin/b2b/dashboard` still works when authorized; unauthorized Retail support without `support_access` returns real HTTP 403, while explicit scoped support route works
- Unregistered PLATFORM_OWNER does not gain an elevated session or authority
- B2B_ADMIN's daily entry remains on the operational dashboard
- No underlying account/role assignment, platform owner registry, permissions or financial data is changed by normal GET navigation

```sh
cd backend
php artisan test --filter=V2ControlPlaneDefaultLandingTest
vendor/bin/pint --test tests/Feature/V2ControlPlaneDefaultLandingTest.php app/Http/Controllers/Admin/AdminShellController.php
```

Accept only when Repository Policy, FOODEX V2 Isolation Gate, Backend full suite, AR/EN, PHPStan/Pint/Composer audit, MySQL/Redis fresh install-upgrade-recovery and exact-head `required-ci-gate` are green on the PR.

## Surface impact classification

| Surface | Status | Evidence |
| --- | --- | --- |
| Backend | Affected — management Dashboard entry `GET /admin` only | Existing controller redirect + real HTTP tests |
| Dashboard | Affected — privileged entry now resolves to existing Administration Hub | Existing localized responsive administration view; no new HTML/CSS to add |
| Customer Flutter | Not affected | No customer REST path/payload/Flutter permission or session change |
| Driver Flutter | Not affected | No driver REST path/payload/Flutter permission or session change |
| Van Flutter | Not affected | No van REST path/payload/Flutter permission or session change |

No new mobile impact issue is warranted for a management-web-only default redirect. FOODEX B2B VAN ONLY / B2C DRIVER ONLY fulfillment is unchanged. This is not evidence of device-browser visual QA or a ban on explicit elevated operational actions; those separate controls require their own acceptance if mandated by policy. Maintain Finance maker/checker and TenantContextResolver hard guards.

## Existing contracts updated after full-suite verification

The first complete backend run on the real P130 redirect was intentionally **not green**: five legacy tests still required HTTP 200 + the generic executive overview at `/admin` for SUPER_ADMIN. The route now deliberately redirects to the control plane, so those expectations were outdated rather than an authorization or PHP error. We changed only the affected acceptance/benchmark tests within this same issue-owned PR:

- `AdminCatalogManagementTest`: confirm Arabic privileged default redirect and RTL administration page while preserving the explicit B2B Sidebar RTL assertions
- `AdminSecurityGovernanceCompletionTest`: establish the actual privileged administration session, then prove revocation still logs the user out
- `AdminShellTest`: distinguish the privileged default hub from explicitly authorized Wholesale executive view, including the fresh-install zero-store state
- `Performance/CurrentMainPerformanceBaselineTest`: keep the historical B2B executive dashboard benchmark on the explicit `/admin/b2b/dashboard` URL; do not silently benchmark a 302 redirect as a successful 200 page

These changes align tests with the intentional P130 route contract. They do **not** weaken any authentication, tenant access, MFA, financial approval, or component performance assertion. Full exact-head CI must be re-run, and only its final result is acceptance evidence.

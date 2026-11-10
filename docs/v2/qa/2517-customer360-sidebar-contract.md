# V2 #2517 — P187 Customer 360 authorized Sidebar destination

**Source:** FOODEX Unified RBAC/ERP/Sidebar Discussion, 2026-11-09, legacy Sidebar 187 / native paragraph P176 (subject to final source matrix crosscheck). **Integration:** `Foodex_V2` exclusively. **Parent:** #2503.

## Scope and decision

**REUSE → VERIFY** existing operational Customer 360, not a second dashboard or a placeholder. The authoritative Laravel route `admin.customer-360.index` is already implemented by `Customer360Controller::index`, with `admin.customer-360.show` for individual customer records. It returns real paginated, filtered records and uses `OperationalTenantScope` and role-specific access to constrain visibility.

Existing `AdminNavigation::groupsFor` exposes `key=customer_360` under `group=accounts` once, with label `admin.customer_360`, route `admin.customer-360.index`, parameters `[]`. Permission projection is `customers.view` in scoped contexts or `b2b.accounts.view` for permitted global wholesale accounts. `backend/config/admin_sidebar.php` already declares the same key under Accounts, scope `both`, permission mode `any`. An unauthorized user must be denied server-side even if a Sidebar layout or URL is fabricated.

**Business channel:** Customer 360 is an existing shared CRM customer identity surface, *not* a combined wholesaler/retailer data grant. B2B managers see wholesale domain only; B2C store admins see only customers assigned to their authorized stores. A foreign-store or foreign-customer HTTP query is 404. Super Admin's existing platform-level role remains unchanged. This work does not certify ERP modules, grant entitlements, expose finance functions or activate a disabled module. The page is already live; no new operational module/readiness dependency is invented.

**HTTP/Sidebar convergence:** The real `B2B_ACCOUNT_MANAGER` global job role has `b2b.accounts.view`, yet the legacy `admin.channels.b2b.global_roles` dashboard allowlist excludes it. Originally the permission-projected Customer 360 Sidebar link therefore led to HTTP 403. `Customer360Controller::access` now admits this one role to the **existing Customer 360 route** only when it also has `b2b.accounts.view`; it still derives permitted wholesale store IDs from `OperationalTenantScope`, and no B2C store scope is conferred. It does **not** add the role to B2B dashboard/channel allowlists, authorize unrelated modules, or relax any Retail/other-role guards. Revoking the role or permission prevents this narrow allowance. This closes a dead navigation link instead of weakening the test.

**Placement/UI:** Keep canonical existing Customer & Sales → Accounts/Customer 360 Sidebar item and page, with existing Arabic and English translation keys and RTL/LTR shell. Fallback is normal Sidebar search/business hierarchy and browser Back. No new screen, popup, persistent state, or layout; the only runtime change is the server-side Customer 360 role/permission convergence.

## Implemented executable regression

`backend/tests/Feature/V2Customer360SidebarContractTest.php` (Laravel, disposable DB via `RefreshDatabase`) exercises:

1. Existing named route is registered; global `B2B_ACCOUNT_MANAGER` with actual permissions has exactly one authorized accounts-group destination, rendered Customer 360 page, `navContext=customer_360`, wholesale-only access and both Arabic RTL and English LTR response.
2. Actual `B2C_STORE_ADMIN` with scoped store role can list and view its own Customer 360 records; cannot see foreign-store email, open a foreign customer ID (404), or manipulate `origin_store_id` to view a foreign store (404). Both locales exercise real HTTP.
3. An active but roleless user has neither Sidebar destination nor direct GET permission (403) in either locale.

All cases use final server-authorized Navigation and real existing HTTP controller; no assertion mistakes link hiding for data authorization. Existing DashboardCustomer360Test continues to cover customer credential/finance actions, account ledger and invoice/report authorization. No seed data is altered outside test transaction.

## Explicit cross-application impact

| Surface | Impact | Evidence |
| --- | --- | --- |
| Backend/API | Customer 360 admin HTTP authorization only | Narrow `B2B_ACCOUNT_MANAGER` + `b2b.accounts.view` scope in `Customer360Controller`; no public/mobile API contract change |
| Dashboard | Existing Sidebar route and real customer page confirmed | Real GET, AR/EN, exact item, scoped actor permission and foreign-store denial |
| Customer app | NOT AFFECTED | No Flutter, customer auth/API/payload/catalog pricing/deep link code changed |
| Driver app | NOT AFFECTED | No Flutter, dispatch/delivery/payment/GPS/API change; B2C DRIVER ONLY unchanged |
| Van app | NOT AFFECTED | No Flutter, Van collection/route/visit/dispatch/API change; B2B VAN ONLY unchanged |

Security: data visibility remains enforced server-side. The scoped Customer 360 HTTP allowance aligns a pre-existing role permission and Sidebar link; it is not an unrestricted new permission, channel dashboard grant, or store-scope bypass. No migrations, account modifications, storage or background jobs, and no visual override can grant access. No mobile subissue is required because no Customer/Driver/Van runtime, mobile API, auth, or payload is altered.

## CI, closure and final acceptance boundary

Integrate via **one** issue branch and **one** PR, base `Foodex_V2`; verify final exact-head Repository Policy, FOODEX V2 Isolation, FOODEX Required CI and all applicable children (PHP syntax/Pint/PHPStan/Composer audit/OpenAPI/AR-EN/real Laravel and MySQL-Redis install/upgrade/recovery), and only then merge and delete merged feature branch. Record SHA and CI in Issue #2517.

Under owner's latest override in #2503, **close this implementation leaf after verified merge and green exact-head CI**. Manual 320/360/390/412/430/768/1024/1280px browser checks, actual screenreader/focus/keyboard, owner UAT and staging role-store scenarios are **deferred to final FOODEX V2 acceptance (#2503, UX #2750, build #2749)**; do not claim them as already performed. If that final UAT finds a defect, reopen/link a new atomic V2 issue. Never merge into main or deploy production without written authorization.

# V2 #2685 — Customer Service Manager / T122R5 (Wholesale tenant authority)

## Existing authority reused

- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, T122R5; parent #2503.
- Canonical built-in job role `CUSTOMER_SERVICE_MANAGER` lives in `config/job_roles.php`, with the effective legacy seeded grant bundle still in `config/permissions.php`. No new permissions or role names are introduced.
- Existing `User::hasPermission`, `OperationalTenantScope`, `AdminNavigation`, Customer Service workspace, order operations and Security Center enforce authorization. No second RBAC adapter.
- Reuse existing `admin.erp.customer-service` navigation and real Dashboard workspace; **do not create new page/Sidebar entry**. Role profile intentionally suppresses the full ERP and Platform administration hubs and Fleet/Live Tracking.
- Presentation: existing FOODEX shared Dashboard shell, AR/RTL + EN/LTR, keyboard/screenreader accessibility and mobile-web layout are unchanged. No markup, CSS, input, form or translation keys changed.

## Role/route security matrix

| User intent / route | Effective authority and failure behavior |
| --- | --- |
| Service Dashboard `admin.erp.customer-service` | Active manager, existing `customers.view`/service permissions and operational tenant context; real authorized HTML route |
| Customer 360 | `customers.view` and appropriate Wholesale store context; no unrestricted customer data scope |
| Orders / dispatch | Existing `orders.view`, `orders.dispatch` and business transition safeguards; explicit `orders.view` DENY hides order navigation and blocks direct HTTP |
| Service team management | Existing `customer_service.team.manage` and dedicated team manager policies; never grants unrelated global roles |
| Security Center / platform RBAC | No `security.view`, `roles.manage` or `users.roles.manage`; direct HTTP returns 403 |
| Finance maker/checker / operational actors | No `finance.post`, `finance.approve`, Driver delivery execution or `van.login`; no self-approval inferred from customer-service authority |
| Retail/other-store | Manager's global Wholesale role is **not** a Retail store role. Two distinct Retail stores and crafted `store_id` must remain denied, including when a synthetic global user ALLOW override exists |
| Inactive / roleless identities | Zero privileged Sidebar links and no access to protected Customer Service or Security URLs |

Module/entitlement readiness is not inferred from a Sidebar visibility flag. WhatsApp campaign links require `RuntimePolicy` independently (covered by `CustomerServiceManagerWorkspaceTest`), and existing lifecycle business paths keep their own SoD and audit controls. Source T122R5 role does not have an unconditional `*` grant.

## New non-duplicate tests

Only `backend/tests/Feature/V2CustomerServiceManagerAuthorityTest.php` was added; it supplements, rather than copies, the already-existing `CustomerServiceRoleClosureTest`, `CustomerServiceManagerWorkspaceTest` and `CustomerServiceWorkspaceTest` suites. It targets previously separately unevidenced combination boundaries:

1. Role's exact reconciled catalog/seeded grants, English/Arabic presentation, and absence of security/finance/Driver/Van impersonation powers.
2. Real Wholesale Customer Service HTTP route, Sidebar navigation and direction in AR/EN; Security Center 403.
3. Two distinct foreign Retail stores, including forged context and explicit global ALLOW that may not grant B2C access.
4. `orders.view` user DENY takes priority over role and cosmetic Sidebar visibility; direct Orders HTTP 403 while Customers 360 remains authorized.
5. Inactive/roleless direct HTTP and navigation denial.

No production account data, API routes, existing app UI, migrations, config, stored roles or permissions are changed. CI must pass exact PR head: Repository Policy, FOODEX V2 Isolation, Backend Fast-Fail/Pint/PHPStan, full Laravel tests, AR/EN, MySQL/Redis Fresh Install/Upgrade/Recovery and terminal Required CI Gate.

## Customer / Driver / Van impact

| Application | Explicit classification |
| --- | --- |
| Backend | Additive isolated Laravel feature tests only |
| Dashboard | Exercise existing service entry, no runtime/menu/UI code touched |
| Customer | No Flutter/customer API, credit/pricing/order payload or login change |
| Driver | No Flutter/Driver API, B2C DRIVER ONLY dispatch or authorization change |
| Van | No Flutter/Van API, visits, B2B VAN ONLY dispatch or authorization change |

This PR does not establish actual deployed account assignments, approval-delegation ownership, full WhatsApp integration/provider readiness, or final mobile/desktop human visual acceptance. Keep #2685 **OPEN** until any remaining true T122R5 operational/source acceptance is evidenced, while manual UI/device UAT is deferred under #2503. Only `Foodex_V2` may receive the PR; no `main`, production, release, tags or APK publishing.

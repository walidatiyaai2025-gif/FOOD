# V2 #2822 / T087R02 — Customer Service customer profile view and limited edit

- **Source:** FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09, table T087R02, parent #2503.
- **Isolation:** issue branch `test/2822-v2-customer-profiles-role-http-boundary`; integrate through one PR targeting **Foodex_V2 only**. No `main`, release tag, production deployment, live account mutation or promotion.
- **Placement (Reuse):** existing `Admin → Customer 360` and `admin.customer-360.index` / `admin.customer-360.show`. The identity edit is the existing profile action `admin.customer-360.identity.update` (PATCH); no new route, Sidebar permission policy or screen. Existing Customer Service dashboard links and Customer 360 detail page remain the canonical entry point.
- **Actor / authority:** global `CUSTOMER_SERVICE_MANAGER`, `CUSTOMER_SERVICE_SUPERVISOR`, `CUSTOMER_SERVICE_AGENT` have the existing wholesale Customer 360 customer-visibility grant; `Customer360Controller::access()` and `findVisible()` enforce channel/store visibility on GET and PATCH independently of rendering. `canManageCustomerCredentials()` restricts credential updates to these named service roles and SUPER_ADMIN. Non-Super-Admin service roles can change **email and mobile password only**; forged `name`/`phone` payload values cannot mutate those fields. This narrow credential workflow is intentionally distinct from broad `customers.edit` business-record permission; the agent must not acquire `finance.manage` or other privileges from it.
- **Retail scope:** store-scoped `RETAIL_CUSTOMER_SUPPORT` may see its authorized customer records, never another store's records. It cannot invoke the global customer credential mutation, including by direct PATCH. The view must not advertise the credential action for this actor.
- **Security:** roleless actors, an explicit per-user global `customers.view` DENY, and cross-channel / cross-store URL forgery are rejected on the real HTTP boundary. Forbidden requests do not mutate `platform_customers` or generate `customer360.identity_updated` audit events.
- **Audit:** accepted narrow edits update both the platform identity and canonical login user and emit `customer360.identity_updated` associated with the acting service agent and customer ID. No bank/credit/ledger or dispatch mutation is authorized.

## Automated regression

`backend/tests/Feature/V2CustomerServiceProfilesRoleBoundaryTest.php` covers:

1. Real wholesale Customer 360 list/detail GET in AR RTL and EN LTR; agent's editable identity remains limited to email/password, including attempted name/phone over-posting, and verifies the persisted result + audit.
2. Global service agent cannot GET/PATCH a **genuinely Retail-only legacy identity with no materialized B2B account**, in AR and EN; no side effect. Regular new platform registrations do receive a wholesale identity from `PlatformCustomerService::register()` even when Retail-originated, so wholesale visibility for those dual-channel identities is intentional; the test removes their synthetic test-fixture wholesale records to model the legacy Retail-only case.
3. Store-scoped retail customer support can GET own profile but not foreign store or identity PATCH, in AR and EN; no side effect or audit.
4. Explicit `customers.view` DENY and unprivileged roleless identities cannot GET list or PATCH identity directly in either language, and cannot cause a write.

**Run from `backend/`:** `php artisan test --filter=V2CustomerServiceProfilesRoleBoundaryTest`, then relevant CustomerServiceRoleClosureTest and DashboardCustomer360Test, Pint and the required full GitHub checks (including MySQL/Redis Fresh Install + Upgrade + Recovery).

## Cross-app impact matrix

| Surface | Effect | Evidence / reason |
|---|---|---|
| Laravel Backend / Dashboard | Affected (new HTTP regression tests; no runtime code change) | Existing `Customer360Controller`, canonical RBAC, page routes and audit remain authoritative |
| Customer Flutter | Not affected | No Customer API, auth payload, mobile identity route, contract or client file changed; existing customer login behavior is unchanged |
| Driver Flutter | Not affected | No Driver API, authentication, GPS, assignment, dispatch or route changes |
| Van Flutter | Not affected | No Van API, login, route/visit, B2B fulfillment or dispatch changes |

**Not asserted:** tests added here are not equivalent to a real V2 staging browser / keyboard / focus / screen-reader audit across 320–1280px. Independent acceptance must confirm this before final issue closure when required by `docs/v2/V2_ISOLATION_GOVERNANCE.md`. Full exact-head CI conclusions and merge SHA must be added after they are actually verified.

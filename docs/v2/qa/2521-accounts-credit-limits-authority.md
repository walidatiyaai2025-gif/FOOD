# V2 #2521 — P191 Accounts and Credit Limits authority

**Source:** FOODEX Unified RBAC/ERP/Sidebar Discussion, P191; parent #2503. **Integration:** Foodex_V2 ONLY.

## Placement and business contract

Reuse → Verify existing Customer 360 in the Accounts group; do not introduce a duplicate page or a dead Sidebar link. The canonical navigation key is customer_360, linking to admin.customer-360.index; an exact account opens admin.customer-360.show with its finance tab. The existing credit-limit form PATCHes admin.customer-360.credit-limit.update. Existing AR/EN translations and RTL/LTR shell apply. The account is selected through a searchable business list rather than a raw technical ID field.

The existing controller scopes viewing through Customer360Controller::access, OperationalTenantScope, and PlatformCustomer visibility. Wholesale account viewers need effective b2b.accounts.view; Customer 360 retail access uses customers.view scoped to allowed B2C stores. Mutating a B2B credit limit separately requires SUPER_ADMIN or finance.manage and an authorized wholesale account; a retail finance grant alone cannot cross the B2B boundary. The canonical B2B account record owns credit_limit, and updates are validated, transactional and audit-logged. Absent/inaccessible account identifiers fail closed. Sidebar visibility never grants access.

Customer 360 is a core existing customer/account surface; this task neither turns on nor circumvents an optional ERP finance-workbench module. Disabled optional modules retain their existing separate readiness gates.

## Acceptance regression

The new Laravel suite backend/tests/Feature/V2AccountsCreditLimitsAuthorityTest.php checks:

- B2B_ACCOUNT_MANAGER has a single Accounts group link and working GET in Arabic/RTL and English/LTR, but cannot PATCH credit limits.
- B2B_ADMIN with finance.manage can PATCH a valid limit and creates an audit record, while invalid negative values fail without modifying the accepted value.
- B2C_STORE_ADMIN can see an owned customer, cannot mutate a wholesale credit limit despite its retail finance permission, and cannot read or patch a customer of a foreign B2C store.
- A roleless actor has neither an Accounts destination nor direct HTTP authorization.

Only disposable test database records are used. Existing DashboardCustomer360Test already verifies Super Admin credit-limit editing and account-summary API propagation.

## Cross-surface impact and isolation

| Surface | Finding |
| --- | --- |
| Backend | Adds regression assertions only; no route, controller, migration, credential or contract changes |
| Dashboard | Reuses existing Accounts / Customer 360 / finance tab and localization; tests real GET/PATCH |
| Customer | Not affected: no auth, checkout, balance API, payload, mobile UI or entitlement behavior changed |
| Driver | Not affected: no B2C delivery or DRIVER ONLY dispatch behavior changed |
| Van | Not affected: no B2B delivery or VAN ONLY dispatch behavior changed |

Functional RTL/LTR localization is checked through server-rendered routes; manual responsive, device, keyboard and screen-reader acceptance is deferred to final V2 UX/UAT on #2503 and #2750, not falsely asserted here. No new mobile Issue is needed for a test-only change.

## CI and closure

One Issue #2521, one scoped branch test/2521-v2-accounts-credit-limits-authority, one PR with base Foodex_V2. Require exact-final-head green Repository Policy, V2 Isolation, Required CI Gate and relevant child jobs before merge. Only then close completed with MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL. Never merge to main, release or deploy.
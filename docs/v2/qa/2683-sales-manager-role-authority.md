# V2 #2683 — T122R3 SALES_MANAGER authorization and business journey

**Source:** FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, native table T122R3 (مدير المبيعات); parent #2503. **Integration:** `Foodex_V2` only, not `main`, production, release or cPanel.

## Reuse and placement decision

This issue reuses the existing canonical, seeded `SALES_MANAGER` role without adding a second Sales Manager role, Sales screen, permission set, Sidebar group or pricing engine. It also fixes an actual channel-discovery defect: `backend/config/admin.php` did not include SALES_MANAGER in the **B2B global-role allowlist**, even though the role holds legitimate B2B Sales permissions. The minimal addition enables the existing Wholesale dashboard and domain-hub entry without introducing Retail entitlement. The role's configuration is owned by `backend/config/job_roles.php`, reconciled by `BuiltInJobRoleCatalog`, and rendered through the real `AdminNavigation` projection. #2710 already tests the Wholesale & Sales *persona* using `B2B_ADMIN`; it does not establish this separate `SALES_MANAGER` job role's security.

| Required business metadata | Reused canonical decision |
| --- | --- |
| Domain / entity | Wholesale Sales; B2B Account, Quotation, Order, Price Tier, Customer, Report |
| Actor / goal | Active SALES_MANAGER; lead B2B accounts, pricing, orders and reporting without Finance checker or Platform security privileges |
| Workflow / usage | Daily sales workbench, operational orders and quotations, independent price-list management |
| Placement type | REUSE existing Accounts and Orders Sidebar groups, B2B dashboard/domain hub, pricing tab and Analytics; no new navigation |
| Navigation entries | `customer_360`, `sales_quotations`, `order_operations`, `reports_center`; ready Price Lists in the Wholesale domain hub |
| Canonical HTTP routes | `admin.customer-360.index`, `admin.sales-quotations.index`, `admin.operations.orders.index` with B2B channel, `admin.b2b.module` dashboard, `admin.b2b.pricing-approvals`, `admin.b2b.pricing.save`, `admin.reports.index` |
| Permissions | `b2b.accounts.view/manage`, `b2b.pricing.view/manage`, `orders.view/edit/approve/manage`, `customers.view/edit/manage`, `reports.view/export`; server-side authorization on each HTTP request |
| Channel / store / authority | Wholesale tenant only. OperationalTenantScope and effective permissions; a global grant never creates a Retail store assignment |
| Module/entitlement/readiness | Core Sales role has no required optional modules. B2B price-tier page requires B2bPricingMode enabled for principal Wholesale store |
| Safety | No SUPER_ADMIN or Platform Owner inheritance; no `finance.post/approve`, no procurement PO issuance, no Retail execution or `van.login` |
| Localization | Canonical `job_roles` Arabic "مدير المبيعات" / English "Sales Manager"; real responses checked for RTL/LTR |
| Screen UX | Existing responsive Orders grid/quotations workbench, horizontal page tabs, Dashboard mobile drawer; no new DOM, fields, or route |
| Fallback/back | Existing Accounts and Orders navigation; Wholesale dashboard hub returns to same channel |
| States | Authorized / denied permission / roleless / inactive / forbidden Retail / pricing enabled |
| Expected frequency | Daily high-frequency operations; do not create a second executive module screen |

This source requirement is *not* a request to grant wholesale actor a Retail order scope, customer mobile login, finance SoD capability, or new pricing policy. Actual pricing and order policies remain authoritative at request boundaries.

## Executable coverage

**New:** `backend/tests/Feature/V2SalesManagerRoleAuthorityTest.php` with five Feature tests:

1. Canonical seeded role/grants exactly agree with the role catalog; permissions are explicit and least privilege; bilingual role identity is human-readable.
2. Real SALES_MANAGER accesses authorized B2B orders, customer 360, quotations, reports, and the existing independently authorized ready price-list workspace, in Arabic RTL and English LTR; platform security is forbidden.
3. Real HTTP POST creates a store-scoped B2B tier pricing rule and the authorized view reads it back; no platform security inheritance.
4. A direct effective `orders.view` DENY removes both Orders and Quotations navigation and blocks corresponding HTTP while unrelated Customer 360 remains available.
5. A Wholesale role cannot read Retail even via a forged Retail channel/store or a global ALLOW override; inactive and roleless actors have neither Sidebar nor direct protected HTTP.

Existing #2556 Price Lists, #2523 Quotations, #2524 Orders, #2710 Wholesale persona tests remain canonical and are not duplicated or rewritten. The present regression targets the distinct exact built-in role contract. Browser and staging UAT are reserved for parent #2503; test output and exact-head required CI must be attached to this PR before code-complete closure.

## Impact by surface

- **Backend / API:** Add SALES_MANAGER to the existing **B2B channel navigation/access allowlist** in `backend/config/admin.php`, plus five HTTP/RBAC/real-pricing regression tests. No new API contract, migration, pricing algorithm, route, queue or permission grant. This correction restores the existing channel workspace to an already-authorized Wholesale role; Retail remains excluded.
- **Dashboard web:** The existing B2B dashboard/domain-hub becomes discoverable to SALES_MANAGER through the canonical channel role list; Sales/Accounts/Orders/Reports/Price Lists HTTP routes and responsive shared components are exercised in tests. No new page, JS, markup, navigation group or localization key is introduced; no UI screenshot claim.
- **Customer Flutter:** **Not affected.** Customer token/auth, checkout/price calculation, order state, notifications and API payloads are unchanged by the test/docs-only patch.
- **Driver Flutter:** **Not affected.** B2C-only delivery, wallet, collection, route and proof APIs are untouched; DRIVER ONLY remains B2C.
- **Van Flutter:** **Not affected.** B2B-only fulfillment, inventory, offline/dispatch, visits and settlements untouched; VAN ONLY remains B2B.

No new mobile Issue is justified by this test/documentation change. This is a coding/automated contract closure, *not* acceptance of production user roles, data or deployment.

## Delivery evidence and gates

One branch `test/2683-v2-sales-manager-role-authority`, one PR into `Foodex_V2`. Exclusive paths are this document, the test above, and the **single B2B global-role whitelist line** in `backend/config/admin.php`. Former owner #3297 has independently **merged** into Foodex_V2; this branch incorporates its full final base (including Treasury role) before touching the released config. No edits to `AdminNavigation.php`, #3300 Accounting Manager files or #3301 Commercial Pricing files. No seed/grants/Flutter/release/deployment file altered.

Focused local test: `cd backend && php artisan test tests/Feature/V2SalesManagerRoleAuthorityTest.php`. For merge: exact latest PR head **Repository Policy, V2 Isolation, Backend tests, Backend fast-fail/Pint/security, Localization AR/EN, MySQL/Redis deployment acceptance, applicable Release Validation, terminal `required-ci-gate`** all green. CI success must be evidenced, never inferred. Do not merge if any required check is red/pending. No code from this V2 task can go to `main` or a production release without a separate explicit owner approval.

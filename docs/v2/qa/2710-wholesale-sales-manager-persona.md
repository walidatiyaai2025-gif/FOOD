# V2 #2710 — Wholesale & Sales Manager persona authority (T353R1)

**Source:** FOODEX unified RBAC/ERP/Sidebar discussion, 2026-11-09, `T353R1`; **parent:** #2503. **Integration:** `Foodex_V2` exclusively. This is an authorized source-to-implementation acceptance record, not a production deployment.

## Real implementation, not duplicate navigation

The Wholesale/Sales Manager's business destinations already exist in the canonical Administration dashboard. This leaf verifies their **combined persona journey**, not only an individual link or a role-name template. There is no new route, menu-only visibility grant, duplicate RBAC engine, or synthetic ERP activation.

| Business intent | Canonical Sidebar group | Authorized existing destination | Gate/scope |
|---|---|---|---|
| Overview / نظرة عامة | `overview` | `enterprise_action_center` / `admin.action-center.index` | Existing authenticated operational context |
| Customers / العملاء | `accounts` | `customer_360` and `customer_groups` | `customers.view` / wholesale principal |
| Sales / المبيعات | `catalog` | `commercial_sales_control` / `admin.commercial.sales-control` | `catalog.view`, B2B store context |
| Orders / الطلبات | `operations` | `order_operations` / `admin.operations.orders.index?channel=b2b` | `orders.view`, B2B only |
| Catalog / الكتالوج | `catalog` | `catalog_management` / `admin.catalog.index` | `catalog.view` |
| Reports / التقارير | `analytics` | `reports_center` / `admin.reports.index` | `reports.view` |

The Sales destination intentionally lives inside Catalog & Pricing under the current business-domain information architecture; its placement does not create a separate permission. The source's six business intents map to five canonical Sidebar groups. Empty/unauthorized links must never be represented as enabled destinations.

## Regression acceptance and authorization

`backend/tests/Feature/V2WholesaleSalesManagerPersonaAuthorityTest.php` creates a synthetic, global `B2B_ADMIN` identity and tests **both Arabic/RTL and English/LTR** against real HTTP routes. The composite scenario verifies the five grouped business destinations are present exactly once; all seven real URLs return successful/authorized responses, and the canonical Orders page renders correctly in each direction. A B2B operator does **not** inherit Retail Dashboard, Customer, Orders, or Reports links.

Negative cases assert direct HTTP `403` when B2B attempts Retail/B2C or `channel=all` order context. A deny override on `reports.view` removes the reporting link and blocks direct reporting HTTP access without removing unrelated authorized B2B Orders/Customer links. Roleless and inactive identities cannot gain menu or direct-route authorization. No user/role/permission ledger row is mutated outside disposable test fixtures.

Any ERP module that lacks independently verified entitlement, operational readiness and backend Gate authority remains fail-closed under existing implementation. This issue does not fabricate certified ERP evidence. Business feature endpoints continue to apply existing finance approval, SoD and store/tenant guards; a Sidebar item is never itself an authorization grant. A shared-source change would require coordination because open PRs may own `AdminNavigation.php`; this issue intentionally changes only two new files.

## Mobile app compatibility review

- **Customer app:** no API schema, customer catalog/price/discount/checkout, session, token, notification or storefront changes. This test invokes Dashboard-only routes; existing B2B customer permissions remain server authoritative.
- **Driver app:** no B2C dispatch, B2B access, collection, wallet, delivery lifecycle or application file touched. **B2B must continue VAN ONLY**.
- **Van app:** no Van identity, territory, route, inventory, visited stop, B2B fulfillment or offline sync change. No Driver fallback introduced.

No shared API, database migrations, Flutter code, assets, business services or production routes changed, so no new mobile-impact child issue is needed for this test-only evidence contribution.

## Reproduction and remaining acceptance

Run `cd backend && php artisan test --filter=V2WholesaleSalesManagerPersonaAuthorityTest` and exact-final-head GitHub Actions Required CI covering Pint/PHPStan, backend suite, localization/security checks, real MySQL/Redis installation, upgrade and recovery, repository policy and V2 isolation.

Live owner/browser/device UAT stays **DEFERRED_TO_V2_FINAL** on parent #2503 and UX gate #2750. No claim is made of any live account, real wholesale financial workflow, device build, release or production data mutation.

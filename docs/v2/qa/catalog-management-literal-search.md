# V2 #3606 — Catalog Management literal product/category search

Parent #2503 / #2818 P082. **Integration Foodex_V2 only**; never main, releases, tags, APKs, cPanel or production.

## Verified discrepancy
The existing Dashboard `CatalogManagementController::index` uses parameterized SQL LIKE but previously passed user input `product_q` and `category_q` without escaping wildcard operators. Literal `%`/`_` produced unrelated product/category results inside the authorized scope. No cross-tenant bypass or SQL injection was established.

## Minimal safe implementation
- Product name and SKU use a single bound `LIKE ? ESCAPE '!'` literal pattern on each fixed column, with `!` → `!!`, `%` → `!%`, and `_` → `!_`, deliberate substring `%` around escaped human text.
- Category name uses the same portable SQLite/MySQL/MariaDB explicit escape and bound predicate.
- Unchanged: `visibleStoreIds`, `catalogReadableStoreIds`, migration quarantine, tenant/channel security, existing price/stock/calculated sort data, brand/category ID filters, status, sorting, 25-row separate product/category pagination and all edit/create/audit actions.

## UI and authorization
- **Domain:** Catalog & Pricing; **entity:** Products and Categories; **role:** existing authorized catalog actor; **goal:** find a name/SKU/category by literal human text.
- **Placement:** REUSE `admin.catalog.index` existing Products and Categories tabs and their standard Data Explorer filter fields. No new route, Sidebar navigation, page, label, form, API or UI component.
- Existing AR/EN, RTL/LTR, accessible responsive desktop/tablet/320–430px mobile-web Blade and back/error/empty states remain unchanged, except corrected filter precision. Page route stays server-authorized.

## Real HTTP test coverage
`backend/tests/Feature/V2CatalogLiteralSearchTest.php` verifies:
- Retail store A vs B in authenticated `GET /admin/catalog` for both product and category tabs; fixed field names + bound user text, not SQL injection.
- Combined literal `%_!`, individual `%`, `!_`, valid SKU underscore, ordinary Arabic/English, missing terms, negative SQL-wildcard lookalikes.
- Active/inactive filters; direct other-store access and roleless request forbidden; no changes to product/category/audit counts on search.
- 26 categories validate literal filter result count, page size 25, query/status/store propagation and page 2.

## Cross-app impact
| Surface | Impact |
| --- | --- |
| Backend | Controller read-filter only, no mutation/schema/DTO |
| Dashboard | Existing authorized Product & Category list result accuracy |
| Customer App | Not affected; no Customer API/state/Flutter change |
| Driver App | Not affected; no dispatch/order/wallet API/Flutter change |
| Van App | Not affected; no B2B van catalog API, stock/visit/Flutter change |

B2B=VAN ONLY / B2C=DRIVER ONLY invariant preserved.

## Delivery gate
Exact-final-head Repository Policy, FOODEX V2 Isolation, Required CI full Laravel/PHP syntax/Pint/PHPStan/security/OpenAPI, AR/EN and MySQL/Redis where applicable; reviewed source, SHA-guard merge to Foodex_V2 **only**, verify merge SHA, close atomic leaf CODE_COMPLETE/FINAL_ACCEPTANCE_DEFERRED with evidence. #2503 keeps all final staging/UAT/release readiness.

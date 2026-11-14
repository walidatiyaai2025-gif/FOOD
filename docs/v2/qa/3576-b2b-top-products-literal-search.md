# V2 #3576 — literal Wholesale Customer top-purchased-product search

## Source and authority
- Parent V2 #2503; Customer Wholesale parity #2732; historical B2B Customer Top Products Screen #863.
- Existing authenticated read-only `GET /api/v1/b2b/products/top`, `B2bReportController::topProducts`.
- Before this patch, `q` entered by a user was placed between SQL `%` wildcards without escaping `%` / `_`. `order_items.name_snapshot` and `sku_snapshot` queries could include false-positive historical items, changing totals/ranking/page counts.
- After this patch, the **two existing fixed trusted columns** use bound `LIKE ? ESCAPE '!'` and literal `!`/`%`/`_` escaping. The outer `%` markers intentionally implement substring search. Compatible with SQLite and MySQL/MariaDB without relying on backslash SQL mode.

## Tests and boundaries
- `B2bReportingTest::test_top_products_search_treats_sql_wildcards_as_literal_text_and_preserves_scope` is a real Laravel/Sanctum/RefreshDatabase HTTP test. It proves literal percent+underscore and exclamation markers in historical name/SKU snapshots, ordinary search, no wildcard lookalike in one store, per-store scope, foreign customer exclusion, pagination/empty results, active vs unapproved account and no order-item mutation.
- Preserve existing account resolver/approved B2B account, order owner, channel=b2b, historical order state, date and store filters, aggregation, ranking, page metadata, prices/stock/availability, and API JSON. No route, entitlement, migration or new translation.
- Not a claim of a production exploit: the observed mismatch concerns literal search accuracy. Source-authorization controls and scoped queries remain unchanged.

## Surface and app impact matrix
| Surface | Impact | Verification |
|---|---|---|
| Backend API | Affected — B2B historical Top Products query q only | Authentic HTTP + negative/account/store isolation |
| Dashboard | No Dashboard controller/view/route or navigation change | Existing AR/EN RTL/LTR and RBAC unaffected |
| Customer Flutter | Existing Wholesale Top Products screen consumes identical q and response DTO; more accurate results | #2732 linked; no Dart, request shape or navigation changed |
| Driver Flutter | No Driver route, API, scope, dispatch or DTO change | B2C=DRIVER ONLY invariant retained |
| Van Flutter | No Van route, API, scope or custody change | B2B=VAN ONLY invariant retained |

## Integration gate
- Original Issue #3576, exact branch `fix/3576-v2-b2b-top-products-literal-search`; one draft PR exclusively to `Foodex_V2`.
- Require exact-final-HEAD Repository Policy, FOODEX V2 Isolation, terminal FOODEX Required CI (PHP syntax/Pint/PHPStan/security/OpenAPI, full Laravel, MySQL/Redis, AR/EN) to succeed and source review before integration; no green claim until verified.
- No Setup ZIP, Update ZIP, release/tag, `main`, or production publish. Close this atomic programming leaf only after verified V2-only merge. Parent #2732 and #2503 remain OPEN for wider Customer/device/staging UAT.

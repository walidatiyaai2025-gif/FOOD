# V2 #3571 — literal Wholesale Customer product search

Branch and PR target Foodex_V2 ONLY. No main, tags, cPanel, releases, or production. Parent Customer #2732 and V2 master #2503 remain open for comprehensive UAT. Separate #3563 (Van catalog), #3545 (Dashboard global search), #3565 (App Preview) and #3561 (Address Quality) are owned by other workers and their paths are not modified.

## Existing source issue
Authenticated, approved B2B Customer product API GET /api/v1/b2b/products validates q as string (max 120), but uses an unescaped SQL LIKE substring on products.name/products.sku/products.barcode, so literal percent and underscore unexpectedly match unrelated products. This is a matching accuracy bug; existing B2B account checks and store/channel restrictions stay intact, no claim of a cross-tenant authorization bypass.

## Change
Use a bound SQL LIKE parameter with explicit exclamation escape marker, escaping !, % and _; safe MySQL/MariaDB and SQLite semantics. Preserve allowed store, active B2B catalog, product activation, price-tier enforcement, in-stock-only setting, category/brand filters, pagination, response schema, currency and existing Customer Flutter rendering. No writes, permissions, routes, migration or translations change.

## Tests and impact matrix
Extend existing real Laravel RefreshDatabase B2bPricingTest with authenticated B2B account seeded via canonical helpers: three products share a store, one with literal %_, one wildcard lookalike, one with !_. Test literal name, SKU and barcode positive matching and false-positive denial, explicit escape character, normal search and zero match, no unintended read-side writes. Existing tests retain denied unapproved account, pricing tiers/stock-only visibility and cart minimum ordering.
Backend B2B Customer catalog API search affected; Customer app existing query and JSON/DTO unchanged, no Flutter source changes. Dashboard pricing management unchanged; Driver and Van API/Flutter/fulfillment unchanged. B2B VAN ONLY, B2C DRIVER ONLY.

Closure requires exact-final-head Repository Policy, V2 Isolation, Required CI (PHP lint/Pint/PHPStan/security/OpenAPI, Laravel full suite, AR/EN and MySQL/Redis, plus applicable app gates) terminal SUCCESS and SHA-guard squash merge into Foodex_V2. Defer device/staging/production to #2732/#2503; no early release claim.

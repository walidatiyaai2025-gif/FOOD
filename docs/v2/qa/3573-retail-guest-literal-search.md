# V2 #3573 — Retail B2C guest catalog literal search

## Scope / source
Source: existing public Retail storefront and V2 Customer parity #2733; overarching acceptance #2503. Integration **Foodex_V2 ONLY**, not main, production, tags, releases, cPanel, or a shared PR. Reuse the existing Guest Catalog product browsing capability. No new Sidebar, UI page, API endpoint, permission or master data.

The existing `GuestCatalogController::products()` bound `q` into a SQL `LIKE '%...%'` condition across product name and SKU without escaping special LIKE pattern characters. Typed `%` and `_` could accidentally match unrelated products in the current Retail store, skewing search result counts and pagination. This is a bounded search-correctness discrepancy, not evidence of SQL injection, cross-store authorization bypass or a production incident.

## Backend change
For the existing `GET /api/v1/stores/{store}/products` public product list, escape `!`, `%`, and `_` in the query term to `!!`, `!%`, and `!_` respectively, retain bound values, and explicitly use `LIKE ? ESCAPE '!'` on the two fixed trusted columns `products.name` and `products.sku`. This is portable on SQLite and MySQL/MariaDB without depending on backslash escape SQL mode. Preserve max-120 string/blank validation, normal substring queries, B2C store active/type guard, catalog isolation, category and price filters, sort/direction, 20/100 page limit, response JSON, product prices and availability.

## Automated HTTP regression / denial matrix
Existing `backend/tests/Feature/GuestBrowsingTest.php` seeded Retail store, second Retail store, category, active B2C catalog, product and public storefront are reused. The appended test adds one same-store false-positive lookalike and asserts:
- Literal percent, underscore, exclamation point and combined `%!_` return **only** the correct Retail product rather than wildcard lookalikes.
- Ordinary substring still matches both store products; pagination metadata and a second page remain correct.
- Nonmatching special-character query returns zero products; a product belonging to another active Retail store cannot appear in the requested store; inactive Retail store returns 404.
- Existing earlier GuestBrowsingTest checks guest access, product-detail ownership/other-store 404, categories, images, offers, cart access and B2B/non-B2C store denial. No new data-writing endpoints introduced.

## Cross-surface impact
| Surface | Impact |
| --- | --- |
| Backend / public Retail API | **Affected** search interpretation only, no response or authentication contract changes |
| Customer App | **Affected consumer**, linked to #2733: existing product search `q` receives accurate literal filtered results. Existing Flutter route, schema, widgets, translation and cart unchanged |
| Driver | No endpoint, app, dispatch or B2C Driver-only fulfillment change |
| Van | No endpoint, app, sales authorization or B2B Van-only fulfillment change; #3563 separately handled Van search |
| Dashboard | No UI navigation, source route, RBAC, reports or business action change |
| AR/EN / RTL/LTR | Existing labels, fonts, responses and empty/error UI unchanged; no new translation strings |

## Gates / release boundary
Test actual latest PR head with Repository Policy, FOODEX V2 Isolation, FOODEX Required CI (full Laravel/GuestBrowsingTest, PHP lint/Pint/PHPStan/security/OpenAPI, AR/EN, disposable MySQL/Redis install/upgrade/recovery) and any applicable changed-area validation. Do not claim skipped mobile jobs as executed; no Flutter code or DTO change. Only SHA-guard merge into **Foodex_V2** after exact-head green and source review; record tested SHA, integration SHA, CI links, and close atomic child CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED. Customer end-to-end device acceptance, three synchronized signed APKs and final release/staging gates stay open under #2733/#2503. No main/production promotion.

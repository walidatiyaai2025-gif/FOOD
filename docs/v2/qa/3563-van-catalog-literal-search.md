# V2 #3563 — Literal B2B Van catalog search (P275 / P082)

## Source and ownership
Parent #2605 (P275 — Van Direct Sales), affected Van mobile #2739, master #2503. Integration **Foodex_V2 ONLY**; no main, production, tag, release, APK publishing or cPanel. Atomic bug fix reuses the existing authenticated Van Product Catalog and `VanOrderController::b2bCatalog()`, not a new UI, Sidebar entry or backend service.

## Verified source behavior
Existing query `q` was validated and bound, but constructed SQL `LIKE '%<user text>%'` without escaping its pattern characters. Thus operators `%` and `_` could match unrelated authorized-store SKU/name/barcode values and skew the selected product list/pagination. This is a search-correctness risk, **not evidence of SQL injection or cross-store data disclosure**. No production incident is claimed.

## Implementation
- Escape literal `!`, `%`, `_` to `!!`, `!%`, `!_` in that order. Keep a bound `%...%` pattern and explicit `ESCAPE '!'` for only the three fixed trusted columns: products.name, products.sku, products.barcode. The SQL is portable across SQLite and MySQL/MariaDB, avoiding backslash-specific SQL mode assumptions.
- Existing authorization runs **before** the catalog search: authenticated `app:van` actor, effective Van assignment, B2B-only visit, matching seller store and current active visit; 404 remains the server's denied response. No changes to direct sales quotes/orders, settlement/finance, pricing resolution, pagination, stock, schema, cache or notifications.
- Existing `q` string/max-120 validation and case-insensitive ordinary substring semantics are preserved. No new JSON field, HTTP status, enum, route, OpenAPI schema or translation key.

## Automated HTTP acceptance
`backend/tests/Feature/VanOrderControllerTest.php::test_van_b2b_catalog_search_matches_literal_wildcards_without_cross_store_results` reuses real Van operator/assignment, Wholesale and Retail stores, platform-linked B2B customer, own authorized visit, two valid catalog products and inventory. It asserts:
- Literal `%`, `_`, `!`, combined `%!_` search returns **only** the matching wholesale SKU, not a wildcard-driven unrelated product.
- Ordinary substring matches both authorized products; result count, meta returned/has_more remain correct.
- Same operator cannot query the Retail seller store, even with a special-character search.
- Existing complete Van backend/Flutter/API, B2B commercial pricing and role boundary tests must remain green on the exact final PR SHA.

## Impact matrix
| Surface | Impact and evidence |
| --- | --- |
| Backend Van API | **Affected:** B2B catalog search filter interpretation only; endpoint/auth/JSON/pricing unchanged |
| Van App | **Affected behavior, linked #2739:** existing Van UI sends operator text to this endpoint. Corrected results are consumed unchanged; no Flutter DTO, screen, localization or API call signature change |
| Customer App | Not affected: no Customer endpoint, cart, invoice, user data or Flutter source changed |
| Driver App | Not affected: no Driver endpoint or B2C-only dispatch/delivery change |
| Dashboard | Not affected: no Admin navigation, permission, route or UI change |
| AR/EN / RTL/LTR | Existing labels, direction, responses and empty/error visual states unchanged |

## Release and final acceptance boundary
Require Repository Policy, FOODEX V2 Isolation Gate, full Required CI (Laravel, PHP lint/Pint/PHPStan, security, OpenAPI, AR/EN, real MySQL/Redis acceptance), and applicable Van API contract regressions all **SUCCESS** on exact latest PR head before merge. No Flutter build should be claimed unless the check actually runs. Only merge the one issue PR into Foodex_V2, verify merge/integration SHA, then close this atomic leaf `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED`. Parent #2605/#2739/#2503 retain broader Van runtime, synchronized signed APK, device UAT and release gates. No `main` or production operations.

# V2 #3599 — Operations Orders literal order-number lookup

## Canonical surface and issue-owned boundary

- Source: V2 P082 (#2818), master #2503. Integration `Foodex_V2` only; no main/release/tags/APKs/production.
- Route: existing authorized `GET /admin/operations/orders`, including the B2B and B2C canonical aliases, through `OrderOperationsController::index`.
- Placement: REUSE existing Operations Orders Grid and single-row filters. No new Sidebar, route, actions, localization strings, responsive UI changes, or API version. Current `orders.view`, active-store/channel scope and `DisplayNumber::referenceId` behavior remain unchanged.
- Search fix: `order_number` input is *literal*, not a SQL wildcard expression. Escape user-supplied `!`, `%`, `_` into `!!`, `!%`, `!_` before deliberately wrapping with `%`. Use bound fixed-column `order_number LIKE ? ESCAPE '!'`, including the numeric reference fallback where ID match remains exact.
- This is search accuracy only, not a claim of SQL injection, RBAC bypass or ledger mutation. No changes to dispatch/payment/finance mutations, status history, authorizations, pagination, widget rendering, or query envelopes.

## Authenticated HTTP regression

`backend/tests/Feature/OrderOperationsIsolationTest.php` adds isolated Laravel RefreshDatabase HTTP acceptance:
- Authorized Retail store admin searching literal `%`, `_`, `!` only gets exact matching B2C order numbers; wildcard lookalike excluded.
- Ordinary text substring and `#ID` display reference continue to match.
- Retail other store explicit `store_id` receives 404, B2B channel receives 403; no GET audit mutation.
- Super Admin B2B principal search keeps only real matching `%` Wholesale orders; numeric `#ID` still works; unentitled dashboard actor denied.
- Arabic super-admin locale and existing English Retail fixture exercise unchanged AR/EN displays; no new translation keys.
- Existing orders grid status/pagination/exception/dispatch filters preserved, no new Business screen.

## Runtime parity

| Surface | Scope |
| --- | --- |
| Laravel Backend | Read-only web Controller search predicate |
| Dashboard | Existing Operations Orders search, RTL/LTR, accessible mobile/desktop Grid unchanged |
| Customer Flutter | No public API or Customer search contract changes |
| Driver Flutter | No dispatch/assignment/status/collection API changes |
| Van Flutter | No visit/route/stock/order API changes |

Preserve **B2B VAN ONLY / B2C DRIVER ONLY** fulfillment invariant.

## Gate and closure

Three owned paths only: `backend/app/Http/Controllers/Admin/OrderOperationsController.php` (index search only), `backend/tests/Feature/OrderOperationsIsolationTest.php` (test append), and this new QA file. No concurrent open V2 PR changed this fence at preflight.

All Repository Policy, V2 Isolation, Required CI exact-final-head Laravel, MySQL/Redis upgrade/recovery, Pint/static/security/OpenAPI and AR/EN checks must succeed before SHA-guarded `Foodex_V2` merge. Android Flutter jobs are intentionally skipped if diff is dashboard-only; never misrepresent them as run. Close this code leaf only after verified integration SHA and checks. Parent V2 acceptance #2503 remains open.

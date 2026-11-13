# V2 #2605 / P275 — Authorized B2B Van direct sales

Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P275 (Fleet & Van); parent #2503. Isolated integration target **Foodex_V2**, no promotion to `main` or production.

## Existing canonical journey / placement

- **Reuse → Extend** the existing Van App `Catalog → Order Builder → Order Review` workflow (canonical `VanOrderController` and `CommercialVanSalesBridge`). No second orders, inventory reservation, credit or settlement ledger.
- Actor: authenticated active Van operator with effective Van assignment and `app:van` token; the shared Van runtime middleware remains responsible for authentication and assignment.
- Data: wholesale/B2B customer with an **actor-owned B2B visit in the exact selected/current seller store**. A historical visit from another store cannot authorize a sale. If a current Van store is assigned, client-supplied `store_id` must match it; mismatches return 404 rather than silently switching the store.
- The four commercial entry points `GET catalog`, `GET order-options`, `POST order-quote` and `POST orders` fail closed for B2C customer types and cross-store visits with 404. Authorized B2B quote/create remain delegated to the canonical pricing, credit, inventory reservation, routing, audit and Idempotency-Key services.
- Van App product-catalog customer selector projects only current B2B assigned customers and clears a stale/revoked/retail draft (including cart/quote) before offering checkout. Existing AR/EN empty-state text is specific to assigned wholesale buyers.
- **Dashboard:** Existing Field Operations, Orders and Van registry are the canonical operational surfaces. This PR does not create an unapproved new Sidebar route or compete with #2606 / PR #3311's Field Operations files. P275 Dashboard discoverability and full business acceptance must be independently checked before closing #2605; a mobile-only fix is not a claimed complete ERP/Sidebar destination.

## Cross-app impact

| Surface | Impact | Evidence / scope |
| --- | --- | --- |
| **Van** | **Affected** | Existing #2739 (MOB-V03). Catalog selection excludes B2C; backend direct-sale API rejects retail/cross-store; add Dart selection/revocation regression and Laravel HTTP denial tests |
| **Customer** | Not directly affected | No Customer App code, Customer API route or customer order contract changed. Van-originated order creation still calls existing B2B commercial bridge; existing customer reads are unchanged |
| **Driver** | Not directly affected | No Driver App/API routes, B2C-only dispatch or delivery ownership code changed. Van cannot create B2C order via direct-sale endpoints |

## Authorization / security negative cases

- Legacy B2C Van visit does **not** make B2C checkout/catalog/quote/options available.
- An actor's old B2B visit at Store A is **not** authority to sell from current Van Store B.
- A client cannot override a current assigned Van Store B using Store A's ID, even when the actor has visits in both stores.
- Same-store B2B order-options remains reachable after an authorized B2B visit; pre-existing canonical B2B quote, create and idempotency tests must remain green.
- No new mutable finance, stock, receipt or trip transitions added. Van stock settlement and offline replay remain independently owned by #2739, and Field Operations route grouping remains #2606 / PR #3311.

## Verification (pending CI)

- `backend/tests/Feature/V2VanDirectSalesScopeTest.php`: HTTP allow/deny coverage for B2C, cross-store, stale/mismatched seller selection and authorized B2B options.
- `backend/tests/Feature/VanOrderControllerTest.php`: existing B2B commercial quote, inventory/reservation and idempotency regression.
- `apps/van_app/test/van_direct_sales_scope_test.dart`: B2B projection, stale Retail cart reset, withdrawn assignment and still-authorized selection.
- Validation required on exact final PR head: Laravel feature tests, Pint, static analysis, Flutter analyze/test, AR/EN/RTL/LTR and required GitHub gates. **Do not mark successful until CI evidence exists.**
- UI placement: no new screen/navigation or controls; reuse the existing Van catalog customer Lookup and AR/EN responsive empty state. Full real-runtime visual/accessibility audit remains an acceptance gate.

## Coordination and status

`#2605` owns only the B2B direct-sale customer/store authorization and Van catalog projection. `#2606` / PR #3311 owns the overlapping Dashboard Field Operations implementation files. **Issue remains OPEN** until all P275 source requirements, navigation parity, actual app runtime evidence and CI are verified; a merged PR alone is not full acceptance.

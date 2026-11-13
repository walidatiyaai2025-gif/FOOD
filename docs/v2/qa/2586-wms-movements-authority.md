# V2 #2586 — P256 WMS Movement Log acceptance

## Source / placement
- Requirement: source document P256 (Procurement & Inventory); master #2503. Actor: inventory.view store-scoped warehouse operator.
- Reuse → Extend: canonical Admin > ERP > WMS Workbench > Movements tab; no new screen, route or Sidebar entry. Navigation type: PAGE_TAB; canonical route: admin.erp.wms.index, query tab=movements; parent WMS Workbench; back path Overview.
- Domain: Inventory; entity: canonical stock_movements; user goal: view scoped provenance and open matching Record 360.
- Workflow: authorized read-only history; product is Lookup, type is localized Enum, quantity/reason/time are historical read-only data; no mutation.
- Role / tenant / store / channel / module / entitlement / readiness: identical to inventory.view HTTP scope, active authorized warehouse, inventory_wms module readiness and store entitlement.
- Desktop / tablet / mobile-web: canonical responsive WMS shell, semantic headers, localized AR/EN and RTL/LTR, horizontal navigation and accessible Next link. No added Flutter view.

## Source behavior
- Only inventory rows from the chosen authorized store and authorized warehouse are included. Canonical movement.store_id + joined inventory.warehouse_id must both match before limiting/page selection.
- Descending movement.id keyset; read 51 rows for page of 50, emit Next only when there are more, carry current store and warehouse in pagination URL.
- Record 360 Details link uses the exact movement.inventory_id with selected store + warehouse. Server verifies direct inventory ID against the same context, including cross-store role holders.
- Existing stock movement rows, financials, customer data, inventory balances, and audit provenance are not changed.

## Automated acceptance
- Feature regression: backend/tests/Feature/V2WmsMovementsPageAuthorityTest.php.
- Positive: authorized AR/EN RTL/LTR display, canonical entry point, source type localization, correct Record 360 URL, no mutation.
- Negative: roleless direct GET 403; foreign store/warehouse 404; warehouse authorization 404; foreign inventory ID 404; mixed role deny override 403; revoked entitlement 404; disabled module 403.
- Pagination: exactly 50/50/21 for 121 movement rows, no duplicates or missing rows, no false next link.
- Data retention: test confirms same existing movement rows and no write action.
- CI acceptance: repository policy, FOODEX V2 isolation, PHP/Pint/PHPStan/security/OpenAPI, AR/EN localization, Laravel suite, MySQL/Redis fresh install & upgrade, terminal Required CI Gate — evidence must come from final exact-head run, not the build alone.
- Manual browser/UAT acceptance: DEFERRED_TO_V2_FINAL under #2503 / #2750; not represented as performed.

## Mobile impact (each evaluated)
- Customer: NOT AFFECTED; administrative web GET/Blade and locale only; no Customer API, checkout, order or navigation contract changes.
- Driver: NOT AFFECTED; no Driver dispatch, wallet, proof, route or API change.
- Van: NOT AFFECTED; B2B-only Van assignment/visit, territories and API unaffected.
- Backend: affected (admin read controller / scoped DB query).
- Dashboard: affected (existing Movements tab).
- One issue-scoped PR must integrate to Foodex_V2 only. No main, tag, release or production deployment.

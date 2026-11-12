# V2 #2599 — P269 المناطق: canonical territory authority and geography data integrity

Source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx / Sidebar P269.
Parent #2503. Integration **Foodex_V2 only**. No main/release/tag/production action.

## Placement, entry point and data contract

- Domain: Fleet & Van / Field Operations. Entity: service territory and geographic node.
- Role: platform-authorized Field Operations/territory administrator; retail store-only actors cannot manage platform geography.
- Goal/frequency: maintain permitted national coverage geography and map polygons during field-operation setup.
- Reuse (no new screen): existing **Field Operations → Territories** sidebar entry.
- Route/controller: `admin.field-operations.territories` (GET), `admin.field-operations.geography.store` and `admin.field-operations.territories.store` (POST), existing `FieldOperationsController`; no duplicate route or API.
- Navigation: `field_ops_territories`, authorized through `territories.manage` OR `field_ops.manage`, same conditions used on direct HTTP actions; existing `admin.field-operations` renderer and `navContext`.
- Desktop/tablet/mobile web: existing responsive admin shell, compact forms, paginated 25-row geography and territory grids, common modal and Leaflet drawing surface. Arabic/English labels, document dir RTL/LTR and keyboard-labelled form controls preserved.
- Business fields: nodes, country and selected territory are authoritative lookups; status is a localized enum; names/notes are business free text. Advanced raw GeoJSON remains Super Admin-only.
- Data ownership: geography_nodes and service_territories are **global B2B service geography**, not separate store-owned territorial records. Store-scoped Retail grants are not authority to read/create platform regions. When selecting a default warehouse, only a **currently active warehouse within an authorized B2B store** is valid. No B2C warehouse may be attached through a forged POST.
- Existing app dispatch invariant: B2B → Van only; B2C → Driver only. This change creates no dispatch fallback.

## Security correction

1. Validate a territory's country node is an active root of type `country`; reject forged identifiers that merely satisfy FK `exists`.
2. Validate each supplied governorate/city/district has the expected type, is active, belongs to the same country and descends from the previously selected node. Reject inconsistent branches even when each FK is valid.
3. Verify a submitted default warehouse belongs to an active authorized **B2B** store (using existing `b2bStoreIds` authority), rather than trusting any valid warehouse ID. Reject nullable/unowned/global warehouse records as a territory default until platform scoping is explicitly established.
4. Reject a new geography node's parent from another country (or an attempt to make a country node a child) before write.
5. Return field-specific, translated Laravel validation errors; no partially created geography/territory records on invalid input.

## Automated regression

`backend/tests/Feature/V2TerritoryGeographyAuthorityTest.php`:

- Super Admin sees canonical Sidebar entry and HTTP route in AR/EN with matching RTL/LTR, map and paginated list.
- Retail-store-scoped actor is forbidden from direct GET and both POST mutation routes.
- Forged country type and cross-country governorate fail without creating a territory.
- Same-country but wrong-branch city fails; a correct rooted hierarchy is accepted.
- Cross-country parent creation fails without inserting a node.
- Forged B2C warehouse is denied; active authorized wholesale warehouse can be selected.

Existing tests retain map draw/undo/edit, pagination and page shell behavior. Backend route contracts and mobile REST schemas are unchanged.

## Cross-app impact evidence

| Surface | Assessment | Reason |
| --- | --- | --- |
| Backend | **Affected** | Admin territory/geography validation; preserves tables and existing route/response shape |
| Dashboard | **Affected, existing page reused** | Existing controls receive localized field-level validation; map, list, layout unchanged |
| Customer | **No client/API change** | No `/api/v1` endpoint, product/pricing, identity or navigation changed; storefront remains tenant-scoped |
| Driver | **No client/API change** | No driver app or retail dispatch contract touched; B2C Driver-only preserved |
| Van | **Indirect operational-data effect only** | Territory configuration may influence subsequent B2B route eligibility, but Van app API/schema/navigation are unchanged; B2B Van-only preserved |

## Acceptance limitations / CI

Do not claim device/browser visual UAT or production runtime validation based on a Laravel feature test. Exact-head Repository Policy, FOODEX V2 Isolation and FOODEX Required CI (backend Laravel, Pint/static analysis, security, localization, MySQL install/upgrade/recovery where scheduled) are mandatory before merging. Manual UAT is deferred to parent #2503 / #2750, not asserted here. The territory security correction does not independently certify the entire fleet program or Setup/Update ZIP releases.

If the full required checks fail, fix the **same** branch/PR. Close the leaf only when its full source-scope implementation is proven and integrated into Foodex_V2 under the latest parent decision, with `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL`.
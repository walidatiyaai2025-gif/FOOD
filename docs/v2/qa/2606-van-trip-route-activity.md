# V2 #2606 — P276 Van Trips: authorized B2B route activity

## Source and bounded implementation
- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, source P276, V2 umbrella #2503, branch `Foodex_V2` exclusively.
- Domain: Fleet & Field Operations. Entity: canonical `van_visits` and route/Van metadata, not a new synthetic trip or finance ledger.
- Actor: an authenticated and authorized operator with both `drivers.b2b.view` and `customers.view` (existing HTTP guard), scoped to allowed Wholesale/B2B stores; no Retail/Driver bypass.
- Goal: discover B2B Van route activity and filter existing visit evidence by exact recorded route and lifecycle status.
- Workflow: daily operational read/review, 30 rows per page with query-preserved filters; read-only, no new approvals or settlement actions.
- Placement: **Reuse → Extend** the canonical Admin Fleet & Field Operations > Visits & Routes page. `PAGE_TAB` reached through the existing authorized Sidebar `field_ops_visits`, route `admin.field-operations.visits?trip_view=trips`, controller `FieldOperationsController::visits`, shared `admin.field-operations` renderer. Do not add a duplicate Sidebar destination.
- Navigation metadata: `domain=field_operations`, `role=B2B-authorized fleet`, `capability=Van route activity`, `navigation_owner=field_ops_visits`, `navigation_type=PAGE_TAB`, `parent_surface=Visits & Routes`, `permission=drivers.b2b.view + customers.view`, `back_path=admin.field-operations.visits`.
- Business fields: `trip_route` is a Lookup from the currently authorized canonical B2B visit route metadata, `trip_status` is a service lifecycle Enum, and no user-entered numeric Van/store/internal ID is introduced.

## Trust boundary and explicit limits
- B2B-only `VanVisit::query()` and existing server-side B2B store filters are applied **before** route/status filtering. Retail/B2C visits never enter the result or filter options, including for Super Admin. Routes use exact matches against the existing canonical metadata keys `route_key`, `route_code`, `route`.
- Van code labels are resolved for at most the 30 authorized records in the current page. Status values are AR/EN-localized, metadata escaped through Blade, route selection is server-filtered, pagination preserves filter query parameters.
- An operator-controlled free-text route reference is **not** an independently verified trip ID. This view never claims a trip start/close, route load, stock transfer, reconciliation, settlement, cash collection or journal. A separate authoritative Van trip session and verified close/settlement source must be proven before treating the broader P276/P277 workflow as accepted.
- Van detail, visits, dispatch, collection, inventory, orders and GL mutation paths remain unchanged; there is no new API or migration.

## Impact matrix
| Application | Impact | Evidence |
| --- | --- | --- |
| Customer | Not affected | Admin-only GET presentation of existing `van_visits`; no Customer REST/API/auth/notification/order change |
| Driver | Not affected | B2C remains DRIVER ONLY; query explicitly excludes `customer_type=b2c`, no Driver code/route change |
| Van | No Flutter/API contract change in this bounded delivery | Existing B2B Van visit and route metadata are read without mutations. Full trip lifecycle parity must be reviewed if a new canonical trip session is introduced |

## Acceptance and repeatable checks
- Focused test: `php artisan test --filter=V2VanTripActivityAuthorityTest` (AR/EN authenticated page, canonical page tab discoverability, B2C exclusion, exact route/status filters, denied Retail-only user).
- Confirm the existing `FieldOperationsAdminExposureTest`, `VanVisitControllerTest`, `FieldOperationsDashboardCompletionTest`, Sidebar parity, localization quality, and backend/static analysis tests.
- Inspect actual page from desktop/tablet/320–430px mobile with keyboard, focus order, RTL/LTR, tab/filters, empty state and server-side navigation. Shared Dashboard shell/table/filter primitives are reused; no screenshot CI is required.
- Mandatory exact-head GitHub `Repository Policy`, `FOODEX V2 Isolation Gate`, `FOODEX Required CI Gate` (Laravel, Pint, PHPStan, security, AR/EN, MySQL/Redis acceptance and terminal required-ci-gate) must all succeed prior to merge.
- **P276 full acceptance stays OPEN** until authentic Van trip identity/operational lifecycle and all impacted Van application contracts are established and verified. This read-only phase must not be passed off as a full trip settlement implementation.

No merge to `main`; no tag, APK, release or production deployment is authorized.

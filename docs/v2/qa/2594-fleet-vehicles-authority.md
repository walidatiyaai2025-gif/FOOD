# V2 P264 — Vehicle registry in canonical Field Operations

Issue: #2594 | Parent: #2503 | Sidebar source matrix P264 | Original document paragraph P253 | Integration target: **Foodex_V2 only**.

## Placement and security contract

| Decision | Source-backed implementation |
| --- | --- |
| Business domain | Fleet & Van, active wholesale fleet vehicles |
| Entity | Van fleet registry, vehicle detail, registration and suspension |
| Primary persona | Authorized global B2B field-operations Viewer and Manager; Super Admin |
| Goal and workflow | Discover vehicle registry, read vehicle/assignment status and manage available vans without leaking the fleet to Retail users |
| Screen/navigation | **Reuse** canonical `SIDEBAR → Field Operations → Vans`, item `field_ops_vans`; do not introduce separate Vehicles screen or second Sidebar link |
| Route and entry | `admin.field-operations.vans` → `admin.field-operations.vans.show`, page `admin.field-operations` with sections `vans` and `van-detail`; existing list/detail back path and responsive app shell |
| Read authority | Global `drivers.b2b.view` via `AdminNavigation::routeItemAny` and `FieldOperationsController::authorizeAny` on *each* GET; actual route must exist |
| Write authority | Independent `drivers.b2b.manage` for vehicle creation and suspension; read/view does not imply write |
| Tenant / channel semantics | Van registry is an existing **global B2B fleet control-plane resource**. The Van record lacks a `store_id` ownership column. Do **not** falsely claim row-level multi-store filtering of this global registry. Retail-only and roleless actors must have neither a link nor read/write HTTP access. Strict B2B van dispatch vs B2C Driver-only remains |
| Entitlement/readiness | Existing Van registry is core field operations, not an ERP BI or extra feature flag. No role/module/entitlement is enabled by the test |
| AR/EN and RTL/LTR | Existing `admin.field_operations.vans` labels and `dir` in the canonical responsive Dashboard page, both locales; no new UI copy |
| Desktop/tablet/mobile web | Reuses existing Field Operations responsive page layout, 25-row paginator, forms and vehicle details. No new HTML/CSS, dialogs or controls |
| Unavailable/empty/denied | Empty registry remains valid; missing Van detail 404; actor without global view receives 403; reader-only POST creates no Van; actor without manage may not suspend |

This route is not interchangeable with Driver live tracking, Van territory/routing policies, Fleet Tracking Map or a Retail-only driver registry. Source P264 says “المركبات”; the existing `Vans` vehicle registry is the canonical specialized Fleet entity and the smallest supported screen. Related #2601 Van Routing (merged PR #3144) is kept independent.

## Functional and negative verification

File: `backend/tests/Feature/V2FleetVehiclesHttpAuthorityTest.php`. Uses genuine seeded Laravel routes, navigation, `VanRegistryService` and local database records:

1. Global B2B View role receives exactly one `field_ops_vans` navigation entry, real list and detail (HTTP 200), actual created Van identified by code and plate, both locales/directions. Registry confirms the item has `drivers.b2b.view` permission and all four route names exist.
2. View-only role can read, but cannot POST a new Van or suspend an existing Van (403). Database record count and active Van state are preserved.
3. Explicit B2B View+Manage role can create a synthetic Van via the real registration form POST and suspend it via the existing action; resulting DB status becomes `suspended`.
4. Retail-only Store Admin, roleless user and inactive globally privileged actor have no effective Van Sidebar entry and cannot GET registry or POST a new Van (403), both Arabic and English; no unauthorized Van created.
5. Global Finance-only role does not gain the B2B Van privilege merely through a second broad role.

No production migration, auth grant, runtime controller/service change, release artifact, or user data mutation is included.

## Cross-surface classification

| Surface | Change/impact | Rationale |
| --- | --- | --- |
| Laravel Backend and Dashboard | Test-only regression for existing authorized routes, forms and status changes; QA note | Existing server-side authorities remain unchanged |
| Customer Flutter | Not affected | No Customer source, REST API, order, account or public vehicle access change |
| Driver Flutter | Not affected | No B2C assignment, proof, status, routing, release or Driver API contract change |
| Van Flutter | Not affected | No Van app source, B2B assignment, stock, visit, offline sync, API or vehicle schema change |
| Deployment/queues/storage/cache | Not affected | No executable app code, migrations, jobs, configuration or build artifact touched |

No mobile issue is needed for an additive test/docs-only patch. The global Van registry’s lack of per-store ownership remains an architectural fact—not a claim that arbitrary Retail actors can see it.

## Acceptance and release protection

GitHub required checks on the **exact final PR HEAD**: Repository Policy; FOODEX V2 Isolation Gate; FOODEX Required CI Gate, including full Laravel test suite, Pint, PHPStan, Composer security/OpenAPI, AR/EN localization and MySQL/Redis real clean installation, supported upgrade and recovery CI. Do not merge if any required check remains pending, missing, or red. All CI fixes belong to this sole issue branch and PR.

After successful CI and GitHub `base=Foodex_V2` / head / mergeability revalidation, merge only into Foodex_V2 with expected-head verification. Include final CI links, merge SHA and tested artifacts in Issue #2594; only close when functional coverage and mobile impact are evidenced. `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` per #2503; no claim of real device/browser staging, signed mobile APK delivery or Laravel setup/update ZIP production qualification. Parent #2503 remains OPEN.

No main PR/commit/merge, no release/tag/version promotion, no production/cPanel deployment.

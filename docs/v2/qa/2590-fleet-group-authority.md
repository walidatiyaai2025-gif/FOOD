# FOODEX V2 — Sidebar P260: Fleet business group authority

Issue: **#2590** | Master: **#2503** | Legacy Sidebar **P260** → actual source DOCX paragraph **P249** (Fleet & Van). Target integration: **Foodex_V2 only**.

## Canonical placement decision

| Contract element | Verified existing implementation |
| --- | --- |
| Business domain / entity | Fleet & Van → Van fleet live tracking map and globally managed wholesale Van registry |
| Roles / personas | Global authorized Super Admin, B2B Van viewer, actor with effective global tracking privilege, Retail Store Admin (store-scoped only), inactive/roleless user, multi-role actor |
| User goal | Discover only fleet tracking or Van registry pages permitted by the server; inspect current operational locations without seeing foreign-store data |
| Workflow, frequency, density | Recurring fleet monitoring on map; occasional Van registry lookup (existing 25-row paginated grid) |
| Reuse / Extend / Compose / New Screen | **Reuse** the existing Field Operations business-domain Sidebar group, rather than adding duplicate `fleet` or `van` groups, extra routes, or a competing map |
| Navigation type | Existing `SIDEBAR` → `field_operations` group; `field_ops_fleet` for map, `field_ops_vans` for registry, and existing `field_ops_assignments` as related page. Detail is `CONTEXT_ACTION` |
| Canonical routes | `admin.field-operations.fleet` → `admin.field-operations.fleet.feed` (data); `admin.field-operations.vans` → `admin.field-operations.vans.show`. Parent/shell `admin.field-operations` and built-in Sidebar/back navigation |
| Read permissions | Global `drivers.tracking.view` for fleet map **and** HTTP feed; global `drivers.b2b.view` for Van registry. `AdminNavigation::routeItemAny` uses effective permission; `FieldOperationsController` enforces the same rights on GET |
| Tenant scope / channel | Fleet map feed calls `OperationalTenantScope::allowedStoreIds` per channel, independently of Sidebar. The Van tracking route forcibly chooses `actor_type=van` even when clients query driver. Store filter narrows rows to authorized store; a Retail store-scoped role never becomes a global B2B Van/track grant |
| Data and readiness | The field-operations map and Van registry are live core capabilities, not ERP modules. No newly enabled entitlement or disabled-module fallback. `fleet_current_locations` is scoped by channel/store in feed; Van registry itself has no row-level store owner. Do not claim the latter is independently isolated per store |
| Empty, loading, forbidden states | Existing live-map empty/location freshness states. A group with zero authorized children is hidden. Unauthorized HTTP map/Van requests fail 403 and unauthorized feed does not return rows; foreign selected store is rejected |
| AR/EN + desktop/tablet/mobile web | Existing translated `admin.nav_groups.field_operations`, `admin.field_operations.fleet` and `vans` UI labels, `dir=rtl/ltr`, responsive live map and layout. No new HTML/CSS/labels introduced |
| Navigation fallback | Existing field-operations overview/Sidebar, no direct-URL-only destination or new dead/uncertified module |

This group is not a new standalone “Fleet” menu: `field_operations` already owns fleet tracking and Van management. P260 is a **navigation-group authorization** acceptance unit. Related P264 Vehicles #2594 and P271 Routing #2601 are separately merged; their underlying endpoints remain unchanged.

## Real feature regression contract

Additive `backend/tests/Feature/V2FleetGroupHttpAuthorityTest.php` uses seeded Laravel, actual `AdminNavigation`, `FieldOperationsController`, `FleetLocationController::feed`, `OperationalTenantScope`, `VanRegistryService` and `FleetLocationService` with **synthetic** stores/Vans/locations.

1. Super Admin: exactly one Field Operations group, exactly one each real fleet-map and Van-list route in AR RTL / EN LTR. Authenticated real HTTP 200 for both pages, correct view section and navigation target.
2. Global map feed: B2B Van locations across two wholesale stores and a Retail Driver location are seeded. Feed forces only Van actors, even if caller asks for driver. A selected B2B store returns only that Van, a selected Retail store returns no Van, with exact store and Van identity asserted.
3. A global `drivers.b2b.view`-only actor sees the Van list but **not** the Fleet map/finance; list HTTP 200 but direct map/feed HTTP 403.
4. Assigned Retail Store Admin with store-scoped tracking permission does not acquire **global wholesale** `field_ops_fleet` or `field_ops_vans`: neither appears, direct B2B map/feed/Van list are HTTP 403, and asking for a foreign wholesale store cannot leak locations (both locales).
5. Roleless and inactive globally privileged actors have no Field Operations group, and all three direct GET routes return HTTP 403 in both locales.
6. Global explicit `drivers.tracking.view` **DENY** on Retail actor leaves no fleet-map or Van registry link and blocks the map/feed at HTTP level.
7. A Retail Store Admin plus global Finance role still cannot open wholesale Fleet or Van routes merely because a second role broadens other Dashboard permissions.

This acceptance does not claim that every custom permission template grants a real cross-store scope. The HTTP feed is the authoritative tenant filter; an inherited Global permission without a valid store context does not bypass `OperationalTenantScope`.

## Cross-app / delivery impact matrix

| Component | Affected? | Contract evidence |
| --- | --- | --- |
| Laravel Backend + Dashboard | Additive feature test and QA trace **only** | Existing core Field Operations map, Van registry and Sidebar are reused unchanged. Negative HTTP/store-scoped feed tests cover real API/data boundary |
| Customer Flutter | **Not affected** | No Customer app, auth, order/cart/storefront or API payload/routes modified |
| Driver Flutter | **Not affected** | No Driver app/GPS heartbeat schema, B2C dispatch, completion or GPS status changes |
| Van Flutter | **Not affected** | No Van app/offline syncing, B2B assignment, inventory, routing, GPS heartbeat schema or Van runtime API changes |
| Mail/cache/queue/storage | Not affected | No runtime code, background jobs or migrations changed |
| Laravel installer/updater and ZIPs | Not affected | No production package, deploy, migration, runtime dependency or version change |

B2B fulfillment remains **VAN ONLY**, B2C delivery remains **DRIVER ONLY**. No new linked mobile implementation Issue is required because the patch changes only tests and documentation; the source mobile contracts do not change.

## Gates and closure

Verify on **exact last PR HEAD**: Repository Policy, FOODEX V2 Isolation Gate, FOODEX Required CI Gate, backend/PHP syntax/Pint/PHPStan/Composer security/OpenAPI, AR/EN localization, full Laravel suite and real MySQL/Redis fresh install/supported upgrade/recovery acceptance. Fix any red on the **same branch and PR**. Then verify `base=Foodex_V2`, current head, mergeability, affected-file list, no active conflicting worker and exact-final-head checks before integrating only into `Foodex_V2`. Record CI IDs and SHA after merge, release worker lease and close only when evidence supports this completed leaf.

**MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL** by owner decision #2503. Neither manual device/staging browser UAT nor final signed Android APK / actual production Setup/Update ZIP certification is claimed. Parent #2503 remains open.

**Prohibited:** no PR, push, cherry-pick or merge to `main`; no tag/release/version promotion; no production/cPanel deployment without explicit new written owner authorization.

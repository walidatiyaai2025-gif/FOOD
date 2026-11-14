# V2 #3505 — Driver middleware-pinned Retail location scope

Source: V2 MOB-D02 / #2735 and P136 / #2939. Isolated target: `Foodex_V2` only. No `main`, release, tags, staging deployment or live changes.

## Verified schema and scope correction

The migration `2026_09_24_000500_create_delivery_platform_foundation.php` enforces `drivers.user_id` uniqueness. A dual active Driver-profile fixture for one user is impossible; no schema relaxation or fabricated multi-profile test was shipped. The actual narrow risk is a B2C driver's `store_id` changing between `EnsureDriverRuntimeAccess` authorization and `DriverRuntimeContextResolver` execution during a request.

The middleware already pins `driver_runtime_context` (trusted request attribute, not client JSON). The resolver must compare its reloaded driver ID/channel and resolved active store against this snapshot and return its existing HTTP 409 contract for any mismatch. This prevents a GPS heartbeat or location-freshness check from silently executing under a different Retail store during an in-flight reassignment. No new permission, role, route or API field.

## Regression and acceptance

- New `V2DriverPinnedRuntimeProfileTest` validates actual authenticated B2C Driver heartbeat, downstream location-freshness after a valid heartbeat, and persisted Driver/Fleet B2C store scope.
- A simulated post-middleware reassignment to another **active B2C** store must fail with 409; the active Retail channel alone is not sufficient.
- Missing/wrong driver ID or channel in the trusted request snapshot must fail closed. Matching snapshot must resolve successfully.
- Existing `V2DriverRetailStoreSessionBoundaryTest`, `DriverLocationHeartbeatTest`, `DriverFreshLocationEnforcementTest`, and full Laravel suite should remain green.

## App / Dashboard impact matrix

| Surface | Effect |
|---|---|
| Backend/API | Driver heartbeat and freshness resolver validate original middleware-authorized driver ID/channel/store; no response schema change |
| Driver Flutter | Existing heartbeat/assignment API schema and B2C-only execution unchanged; no Flutter source modification required |
| Customer Flutter | No routes, permissions, payloads, notifications or UI changed |
| Van Flutter | No Van runtime or B2B fulfillment changed |
| Dashboard | No role, navigation, UI or Admin mutation changed |

No new visible localization keys; existing 409 error response remains. Full Source and manual device acceptance remain tracked in #2735 and #2503. Atomic issue closure requires full exact-final-HEAD Repository Policy, V2 Isolation and FOODEX Required CI success and verified issue-scoped merge into `Foodex_V2` only.

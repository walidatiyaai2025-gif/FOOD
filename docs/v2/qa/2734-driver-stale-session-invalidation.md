# V2 #2734 — Driver auth revocation bound to the originating session

**Source:** FOODEX Unified RBAC/ERP/Sidebar Discussion (2026-11-09), MOB-D01; P069/P079/P142. Parent #2503. Target: **Foodex_V2 only**.

## Observed security/race defect

The canonical Driver app admits only a \`B2C_DRIVER\` with a current B2C store scope and Driver token. The backend returns HTTP 401 for invalid/expired auth and HTTP 403 for a driver whose current role/assignment/scope is denied. \`HttpDriverLocationHeartbeatClient\` maps these to \`DriverSessionExpiredException\` and \`DriverAccessDeniedException\`; \`DriverLocationTrackingService\` already stops tracking, clears the GPS queue and invokes its \`onSessionInvalid\` callback.

However, \`FoodexDriverApp._configureLocationTracking\` supplied the **unscoped** \`_sessionExpired\` to every controller. If an old request completed after a new login, its callback could sign out the new identity, clear biometric/remember-me state, revoke push binding and dispose the new session's GPS controller. The same stale-request risk existed for notification/deep-link repository resolution and the DriverNavigator's stored \`onSessionExpired\` callback.

## Scoped remedy

- \`FoodexDriverApp._expireSessionIfCurrent(DriverSession)\` checks that the originating token still equals the mounted app's current session token before delegating to existing authoritative \`_sessionExpired\`.
- Both injected Driver GPS controllers and the production \`DriverLocationTrackingService\` receive the **session-captured** callback. This avoids a parallel auth owner or new memory state; current-token revocation still clears the secure session, push/diagnostics and location tracking.
- The existing DriverNavigator's auth-expired callback is captured to its session and the pending push-order lookup's HTTP 401 handler is also tied to the same request's captured identity. A forbidden business operation is **not** mistaken for a global auth failure.
- Direct B2B Driver access remains blocked (FOODEX V2 **B2B=VAN ONLY**, **B2C=DRIVER ONLY**). Non-auth transport/offline errors do not clear a valid session or turn cached data into authoritatively confirmed data.
- No changes to server role grants, tenancy scopes, API payloads, persistence schema, notifications, or Flutter screens.

## QA and user journey

Reusable navigation: existing Driver app login → authorized B2C Driver shell → existing assignment/active delivery → heartbeat and session invalidation → existing login. This is a lifecycle correctness fix, **not** a request for a new UI route.

Domain: field operations / Driver identity. Role: B2C_DRIVER. Entity: current token and store-scoped runtime session. Goal: invalidate only the exact expired/denied token; keep a more recent successful login intact. Workflow: authentication → scoped GPS heartbeat and work → 401/403 current identity → secure logout; old 401/403 → ignore. Frequency: whenever a server callback arrives; navigation type existing workflow step; parent existing Driver shell; AR/EN/RTL/LTR and mobile compact layout unchanged. States: session live, expired/denied, offline retry, switched identity, logged out.

**Executable proof**: \`apps/driver_app/test/driver_stale_session_authority_test.dart\` uses a hermetic fake GPS/controller and two B2C sessions. In both Arabic and English it replaces one authenticated session with another, proves the first tracker is disposed, triggers a delayed callback belonging to the first token and verifies that the new Driver login remains intact, then expires the current token and verifies a transition to the localized login. Existing \`apps/driver_app/test/driver_location_tracking_service_test.dart\` already tests HTTP 401/403 classification and the real controller's auth-failure stop behavior. Existing \`apps/driver_app/test/driver_location_tracking_app_test.dart\` checks location permission gating, logout and controller replacement. Existing Driver auth/login tests validate B2C-only credentials/biometric/session storage. No production HTTP/GPS or test secrets.

## Cross-surface change matrix

| Surface | Change | Contract |
|---|---|---|
| Laravel Backend/API | No | Auth/role/store scoping and existing 401/403 contracts unchanged; no new migrations or finance/auth grants |
| Dashboard | No | User/role assignment control plane and its RBAC unchanged |
| Customer Flutter | No | Customer authentication and session lifecycle independent |
| Driver Flutter | **Yes** | One existing app-level auth invalidation owner now token scoped, plus hermetic AR/EN widget regression |
| Van Flutter | No | Already isolated Van runtime role/assignment invalidation (#2737); no shared changes or B2B Driver fallback |

## Acceptance and delivery boundary

Exact-HEAD Repository Policy, FOODEX V2 Isolation, affected Driver Flutter format/fatal-info analysis/full tests, Driver Android release-validation build, localization AR/EN, and terminal required-ci-gate must all succeed on the final SHA before any merge. Other areas are checked only if the repository's change detector selects them (do not claim skipped checks ran). All changes are in an isolated PR to \`Foodex_V2\` only. No updates to \`main\`, no release/tag, no staging/production/APK distribution, no live cPanel changes.

Manual device/UAT/real account role-reconciliation acceptance remains deferred to the program's final gate #2503/#2750 and is not asserted by this patch. #2735/#2736 continue to own wider B2C fulfillment and finance/wallet flows.

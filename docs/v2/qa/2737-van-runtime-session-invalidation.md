# V2 #2737 — Van runtime role change, access denial and session invalidation

- Source: FOODEX Unified RBAC/ERP/Sidebar Discussion (2026-11-09), **MOB-V01**, P069/P079/P142.
- Parent: #2503. Branch: \`fix/2737-v2-van-runtime-auth-invalidation\`. PR target **Foodex_V2 only**.
- Primary actor: a Van operator with a current backend-authorized B2B Van assignment and an \`app:van\` Sanctum token.

## Defect and authoritative contract

\`EnsureVanRuntimeAccess\` validates the **current** Van role and effective assignment on every protected Van API request. A login or token that was previously valid can later lose its role/assignment. The location heartbeat receives **403** in this case; an invalid or expired token receives **401**. Previously \`VanLocationTrackingController\` correctly stopped GPS on 403 but *did not call the app's session-invalidating callback*. Worse, the production \`main.dart\` location factory never supplied that callback. The old app shell and remembered credentials could remain visible after the server had rejected the runtime authorization.

A 403 from this **specific heartbeat**, unlike an arbitrary forbidden business operation, is evidence the entire Van runtime context is no longer authorized. The existing \`FoodexVanApp._logout\` owns the session, secure persistence, biometric preference cleanup, push unbinding and location-controller disposal. Reuse that path rather than creating independent security state.

## Implemented behavior

1. \`VanLocationTrackingController.syncNow\` now invokes the existing \`onSessionExpired\` callback on **both** HTTP 401 and 403, after stopping tracking. The HTTP status is classified by the existing \`VanApiClient\` and is not inferred from localized error text.
2. \`VanLocationTrackingFactory\` accepts an immutable session-specific invalidation callback. The real \`main.dart\` factory passes it to the actual controller; it is no longer only a test-time optional callback.
3. The app callback verifies that the invalidated session token is still the **current** token before calling the one authoritative logout path. A late heartbeat from an old/disposed tracker cannot clear a freshly authenticated Van session.
4. \`VanOfflineException\` still leaves the tracking cadence and saved session intact for future retries. No local authorization success is fabricated offline: state-changing Van APIs remain server-checked and 401/403 revoke once an authoritative response arrives.
5. The previous #2702 Van login denied-403 mapping remains unchanged and is independently protected. This issue covers *already authenticated Van runtime*, not the initial login implementation.

## Source/role/channel safety and cross-app impact

| Surface | Change | Evidence / contract |
|---|---|---|
| Laravel Backend/API | No runtime change | Existing per-request \`EnsureVanRuntimeAccess\` rejects inactive/revoked/unassigned session; the merged #2702 real Laravel test already covers role removal, ended assignment, unauthorized Van ID, B2C Driver token denial |
| Admin Dashboard | No runtime change | Role/assignment UI and administrator action approvals are unchanged; no new Sidebar item or fallback |
| Customer Flutter | Not affected | No shared Customer auth class, customer token, API payload or storefront route changed |
| Driver Flutter | Not affected | No B2C driver token or assignment route changed; Driver app remains the only B2C fulfillment executor |
| **Van Flutter** | **Changed** | \`app.dart\`, production \`main.dart\`, location controller and hermetic test; stale Van session is cleared on authoritative heartbeat access loss |
| Offline / push / biometrics | Indirect | Existing secure-session cleanup and push revoke are invoked through the existing logout path; no new persistent secrets, biometrics or fake offline data |

**Operational UI placement:** existing Van app shell, hidden session lifecycle transition to existing Van login; no new screen. Domain = Van Field Operations, entity = current Van session and assignment, goal = revoke stale runtime access, workflow = authenticated→unauthorized→login, frequency = immediate on authoritative heartbeat response; navigation = existing auth route; back path = credentials/biometric re-auth only. Existing AR RTL and EN LTR login layouts and accessibility remain unchanged, and tests render both locales.

## Acceptance and test plan

\`apps/van_app/test/van_runtime_session_invalidation_test.dart\` tests the **real** controller's 401 and 403 behavior and exactly-once callback, offline retry without invalidation, app shell logout in AR and EN (including persisted session + auth preferences), and old-token event after new login not revoking the new identity. No production network, GPS, push or passwords in the test.

Run exact final-head Repository Policy + V2 Isolation + Van Flutter analyze/tests + Van Android CI + Laravel tests/security/static checks + AR/EN + MySQL/Redis Fresh Install/Upgrade/Recovery; only the terminal \`required-ci-gate\` success qualifies for a guarded squash merge into Foodex_V2. The V2 owner defers real device/staging/UAT until final gate #2503/#2750; do not claim that acceptance or a production APK/release.

**Non-goals** under separate leaf issues: Van territory/route assignment matrix (#2738), stock/offline consignment reconciliation (#2739), replacing all operation-specific 403 UIs with forced global logout (unsafe because an authorized session may legitimately be denied a single finance operation). No main, no production.

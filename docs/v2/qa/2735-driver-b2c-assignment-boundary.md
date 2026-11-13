# V2 #2735 — Driver B2C assignment response contract (MOB-D02)

Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` P110 / T115R04, parent #2503. Only permitted integration: `Foodex_V2`.

## Investigated end-to-end authority

- **Server truth:** `backend/app/Http/Controllers/Api/V1/DriverAssignmentController::index` resolves a current driver and channel then constrains `driver_assignments` to `driver_id`, `assignment_type`, and assigned `store_id`. The `assign` handler explicitly rejects B2B orders; Wholesale fulfillment is **Van only** and Retail fulfillment is **Driver only**. Assignment status/proof routes, failed-reason lookups, notifications, finance and GPS gate remain on their canonical server-authorized boundaries.
- **Client:** `HttpDriverAuthRepository` accepts only an authenticated `B2C_DRIVER` with matching B2C `driver_scope` and a positive store ID. `HttpDriverAssignmentRepository.list` rejects explicit B2B callers before network IO, but previously treated a server response with unexpected B2B entries as a successful B2C list by silently filtering foreign channel entries **after** parsing. This silently concealed a broken authorization/scope contract during an API regression. It must not show either a success or a partial list when the server responds with cross-channel data.
- **Defensive change:** check each assignment's `assignment_type` against requested `DriverChannel.b2c.name` **before** parsing the assignment's nested customer/order details. A mixed/foreign/missing/unknown channel raises the existing typed `DriverApiException` and rejects the entire payload. No client-side filtered list can mask an accidental server leak. A normal B2C list and its server-authorized `available_statuses` are unchanged. HTTP 401/403 still map separately to session expiry/authorization denial; server-side access is never delegated to the mobile parser.

## Scope and user journey

Actor B2C Driver; origin existing Driver sign-in; entry existing `DriverNavigator` deliveries list; entity assigned delivery; role/permission/channel/store from server; UI route kept; workflow sign in → fetch assigned B2C deliveries → inspect available server-authorized transitions → status/proof and notifications. This change touches the *fetch contract* only, not dispatch, completion, photo capture or push state. Placement: **Reuse** existing deliveries list and existing API repository; no new screen/route, Sidebar or business Master Data. AR/EN/RTL/LTR and mobile layout are unchanged. An authorization or contract mismatch is a failure, not a fabricated empty state.

New hermetic Flutter test `apps/driver_app/test/driver_b2c_assignment_boundary_test.dart` proves a legitimate B2C response still parses statuses and allows a canonical status request, and mixed B2C/B2B, foreign-only, missing or unrecognized channels fail closed before foreign nested details are consumed. It also verifies B2B callers have zero outbound network requests and HTTP 401 vs 403 remains distinct. Existing source and tests already cover server assignment lifecycle/store boundaries (`backend/tests/Feature/DriverAssignmentLifecycleTest.php`, `TenantOperationalIsolationTest.php`, `DriverJourneyE2EAcceptanceTest.php`), proof uploads (`apps/driver_app/test/driver_completion_api_test.dart`), and notification audience (`backend/tests/Feature/NotificationAudienceIsolationTest.php`). These existing tests are not claimed to have run when backend/other CI jobs are skipped by changed-area detection.

## Cross-app / subsystem impact

| Surface | Impact | Evidence / boundary |
|---|---|---|
| Backend / API | No runtime change | Existing server query constrains driver ID, store ID, assignment channel, and credentials; no new schema, permission, API field, or OpenAPI contract |
| Dashboard | No runtime change | Existing authorized B2C dispatch and assignment control-plane; B2B dispatch uses Van, no new links |
| Customer | No runtime change | Independent Customer order/notifications; no shared API schema or login changes |
| Driver | **Yes** | Existing HTTP Driver assignments list rejects mixed-channel response; new hermetic Dart tests; status/proof/notifications not changed |
| Van | No runtime change | B2B Van-only app and assignment engine unaffected, no Driver fallback |

## Acceptance and explicit remaining work

This scoped change is production-code security/contract hardening, not full source acceptance for the broad #2735 P110/T115R04 workflow. The parent issue must remain **OPEN** after merge unless independent complete end-to-end proof/notification/dispatch/backoffice role/store matrix (including real device UX and notification delivery) is established. Existing tests cover portions, but green mobile CI on this diff alone does not prove the entire operational journey. Final manual device/staging acceptance deferred by owner under #2503; keep an evidence-based issue handoff for next worker.

Merge only from the one issue branch and PR to `Foodex_V2` after exact-head Repository Policy, FOODEX V2 Isolation, Driver Flutter format/analyze/tests, AR/EN localization, Driver Android release-validation and final `required-ci-gate` all pass. Do not merge into `main`, publish an APK/release/tag or deploy production/staging.

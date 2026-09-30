# APP-PREVIEW Final E2E / Runtime Parity Acceptance

Parent: #498  
Acceptance task: #571; final reconciliation: #498  
Final reconciled baseline: `main@3dffc2892cd5f95d53002a18eb4d55a1e5df9232`  
All previously identified repository child gaps are merged on `main`: #564 / PR #570, #573 / PR #577, #574 / PR #579, and #575 / PR #580.

## Status rules

- **PASS** means a merged automated test/CI contract already proves the requirement, and #571 re-runs the relevant evidence in `.github/workflows/app-preview-acceptance-ci.yml`.
- **BLOCKED** means the acceptance result depends on an active integration/external environment that is not yet available on audited `main`.
- **NOT IMPLEMENTED** means the audited repository lacks the required behavior or automated parity gate. A scoped child issue is linked.
- Code presence without an executable test is not sufficient for PASS.
- Deployment/runtime URL availability is external evidence and is never inferred from repository configuration.

## Customer acceptance matrix

| Requirement | Status | Automated evidence |
| --- | --- | --- |
| B2B Wholesale Guest uses real Customer runtime | PASS | `customer_preview_host_runtime_test.dart`: B2B guest uses public wholesale storefront contract; `customer_preview_acceptance_test.dart`: shared Wholesale runtime; `preview_main.dart` boots `CustomerPreviewBrowserHost`. |
| B2B Wholesale Logged-in | PASS | `customer_preview_host_runtime_test.dart`: authenticated B2B resolves revision before real reads; `customer_preview_acceptance_test.dart`: personalized account/base price and pricing tier through preview transport. |
| B2C Retail Guest | PASS | `customer_preview_host_runtime_test.dart`: real Customer app for B2C guest; acceptance viewport/locale matrix renders shared Retail home. |
| B2C Retail Logged-in | PASS | `customer_preview_acceptance_test.dart`: Guest/Auth share the same Retail home widgets; `CustomerPreviewReadBridgeTest.php` reuses production controllers. |
| Customer-specific state/pricing | PASS | Authenticated B2B account price/base price/tier regression; authenticated Retail profile/order/cart reads delegate to real Customer controllers through read-only preview routes. |
| Retail A != Retail B isolation | PASS | `customer_preview_runtime_test.dart`, `customer_preview_transport_test.dart`, `CustomerPreviewReadBridgeTest.php`, `StorefrontPreviewConfigurationTest.php`. |
| AR/EN + RTL/LTR | PASS | `customer_preview_acceptance_test.dart` runs AR/EN over 360/390/430 and asserts Directionality; shared Customer localization tests remain included. |
| Responsive preview widths | PASS | Customer acceptance matrix covers 360/390/430 real shared runtime widths. |
| Full required device-profile semantics | PASS | #574 / PR #579 (`33d3641723c90f52c19cbede17af2c49d216de15`) adds real profile semantics and automated Customer/Driver/backend coverage for viewport dimensions, safe-area/text-scale and supported device profiles. |
| Navigation/deep-link semantics | PASS | Real preview app factory + `customer_navigation_test.dart`, `b2b_journey_test.dart`, `b2c_journey_test.dart`; preview has no alternate Dashboard router. |
| Read-only mutation blocking | PASS | `customer_preview_runtime_test.dart` and `customer_preview_transport_test.dart` block commercial/write methods before delegate/network. |

## Driver acceptance matrix

| Requirement | Status | Automated evidence |
| --- | --- | --- |
| B2B Driver preview | PASS | `AppPreviewSessionTest.php` proves B2B Driver session/channel scope; `driver_preview_runtime_test.dart` resolves B2B preview context with empty bearer identity; shared B2B partition covered by `navigation_test.dart`. |
| B2C Driver preview | PASS | `driver_preview_runtime_test.dart` runs real `FoodexDriverApp.preview(...)` and filters exact Retail store; `DriverPreviewReadBridgeTest.php` delegates list/detail to production controller. |
| Exact store/channel assignment isolation | PASS | Driver preview context rejects Store B/B2B rows in B2C; backend revalidates driver scope and rejects stale/cross-scope sessions. |
| Assignment list/detail | PASS | `DriverPreviewReadBridgeTest.php` GET list/detail; shared `driver_journey_test.dart` opens real assignment detail. |
| Status cards/counts | PASS | Shared real Driver runtime `navigation_test.dart`: four status cards and exact filtered assignment list. Preview uses the same runtime, not a Dashboard renderer. |
| Invoice/order summary | PASS | Shared `driver_journey_test.dart`: assigned invoice receipt/detail and authoritative totals; preview uses the same `DriverJourneyPage`. |
| Mutation/status transition blocking | PASS | `driver_preview_runtime_test.dart`: action remains visible but transition delegate is not called; Web transport rejects mutation/unrelated paths; backend exposes no mutation route. |
| Native navigation capability behavior | PASS | `driver_preview_runtime_test.dart`: native maps action is simulated/disabled and launcher is never invoked. |
| AR/EN + RTL/LTR | PASS | Shared Driver journey verifies Arabic RTL; localization verifies English LTR; preview constructs the same `FoodexDriverApp`/journey widgets. |
| Driver Preview Web compilation | PASS | `.github/workflows/driver-preview-web-ci.yml` builds `lib/preview_main.dart`; #571 acceptance CI repeats the build. |

## Security acceptance matrix

| Requirement | Status | Automated evidence |
| --- | --- | --- |
| Preview token header-only | PASS | Customer configuration/read tests and Driver Web/read bridge tests require `X-Foodex-Preview-Token`; query-token paths are rejected. |
| Preview token cannot authenticate normal APIs | PASS | `AppPreviewSessionTest.php`, Customer read-bridge tests and Driver read-bridge tests prove normal Bearer routes reject preview credentials. |
| Expiry/revocation/reauthorization | PASS | Session expiry/revocation tests; Customer/Driver reads revalidate active actor/target/scope. |
| No Bearer credential reuse | PASS | Customer/Driver runtime identities remain bearer-empty; transports strip/reject Authorization mixing. |
| No token/PII leakage in Inspector/diagnostics | PASS | `AppPreviewDashboardBridgeTest.php` validates source gate, allowlist sanitizer and forbidden credential/body/coordinate fields; session audit test proves token absent. |
| Tenant/store/channel isolation | PASS | Session, Customer bridge, Guest config/SSE, Driver bridge, revision and Retail A/B tests. |
| SUPER_ADMIN support context explicit/audited | PASS | Session, Guest configuration, Dashboard invalidation and revision tests require explicit support access and audit `tenant.support_access.entered`. |

## Draft / Published acceptance matrix

| Requirement | Status | Automated evidence |
| --- | --- | --- |
| Authenticated Draft/Published resolver | PASS | `StorefrontPreviewConfigurationTest.php`; header-only scoped session resolver. |
| Guest Draft/Published resolver | PASS | `AppPreviewGuestConfigurationTest.php`; Dashboard-session scope with no impersonation session. |
| No silent Draft -> Published fallback | PASS | Authenticated and Guest resolver tests assert explicit missing-Draft state. |
| Deterministic Draft vs Published comparison | PASS | Customer configuration test resolves deterministic different Draft/Published payloads; revision checksum/id metadata is exposed to Inspector. |
| Revision publish/rollback/schema compatibility foundation | PASS | `StorefrontRevisionTest.php`: atomic publish/rollback snapshot behavior, unsupported schema rejection and asset-history restoration. |
| Dashboard editor Save affects Preview only | PASS | #564 / PR #570 (`219acf93c5de880fe0e495e8d39f17eed5fc826d`) merged Draft-first Retail/Wholesale editors; Save Draft does not mutate Published/live state. |
| Publish exposes the exact edited Draft to normal runtime | PASS | #564 / PR #570 publishes the exact Draft revision atomically through the authoritative revision contract. |
| Discard/revert editor behavior | PASS | #564 / PR #570 restores Draft from Published without changing the live Published revision. |

## Live-update acceptance matrix

| Requirement | Status | Automated evidence |
| --- | --- | --- |
| Scoped invalidation event creation | PASS | `AppPreviewInvalidationTest.php`: checksum-sensitive Draft events scoped by store/channel/revision status. |
| Authenticated SSE scope + replay cursor | PASS | Token-auth feed is store-scoped, token/PII-free and supports `Last-Event-ID`. |
| Guest/Dashboard SSE scope + replay cursor | PASS | `AppPreviewDashboardInvalidationTest.php`: exact Retail/B2B scope, support audit, permission and cursor behavior without preview session creation. |
| Event carries invalidation rather than business payload | PASS | SSE regressions assert token/PII absence and event model is revision/scope metadata only. |
| Embedded Preview subscribes/reconnects/replays and authoritative-refetches | PASS | #573 / PR #577 (`7c4aa2c1173060817c7205d122c8f5fbf04baa24`) wires scoped invalidation consumption, replay/reconnect and authoritative refetch into the real shared runtime. |

## Preview Center / runtime parity matrix

| Requirement | Status | Automated evidence |
| --- | --- | --- |
| Customer/Driver selection | PASS | Preview Center controls + target-discovery tests; Driver/Customer real runtime configuration. |
| B2B/B2C exact context | PASS | Dashboard target discovery plus backend session/read isolation tests. |
| Guest/Auth persona | PASS | Guest creates no impersonation session; authenticated flow creates/revokes scoped session; Driver is authenticated-only. |
| Draft/Published selector | PASS | Preview Center emits selected configuration; Customer runtime consumes authoritative resolver. |
| Locale selector | PASS | Bootstrap validation + Customer AR/EN runtime tests. |
| Device selector real viewport/profile semantics | PASS | #574 / PR #579 validates supported device profile, width, safe-area/text-scale semantics and exact bootstrap propagation. |
| Runtime/contract health and exact handshake | PASS | Dashboard rejects unavailable/insecure runtime, checks exact origin/source/contract; shared `shared-flutter-v1` structured envelope. |
| Preview Inspector | PASS | Source-gated runtime status + allowlisted metadata tests. |
| Sanitized Diagnostic JSON export | PASS | Deterministic `foodex.preview.diagnostic.v1` export regression excludes credentials/PII-sensitive fields. |
| Customer preview uses real `FoodexCustomerApp.preview(...)` | PASS | Customer host/runtime tests and preview entrypoint. |
| Driver preview uses real `FoodexDriverApp.preview(...)` | PASS | Driver preview runtime tests and Web host/entrypoint. |
| No Dashboard fake renderer | PASS | Dashboard runtime-unavailable path explicitly refuses clone; runtime bridge tests assert no fallback renderer; shared Flutter frame only. |
| Embedded and standalone Web message contract parity | PASS | Customer/Driver bootstrap tests use structured `shared-flutter-v1`, exact parent source/origin and same bootstrap/status envelope. |
| Customer Preview Web compilation in CI | PASS | Customer CI explicitly builds `lib/preview_main.dart`; #571 acceptance CI repeats it. |
| Driver Preview Web compilation in CI | PASS | Dedicated Driver Preview Web CI builds `lib/preview_main.dart`; #571 acceptance CI repeats it. |
| Embedded-vs-standalone automated visual parity | PASS | #575 / PR #580 (`3dffc2892cd5f95d53002a18eb4d55a1e5df9232`) adds the required CI gate and deterministic real-runtime browser comparison; the final PR head completed the parity check successfully. |
| Deployed runtime URL/origin availability | BLOCKED | External/deployment-only. Repository correctly renders unavailable when env-backed Customer/Driver preview runtime URL/origin/contract is not configured; no deployment state is fabricated here. |

## Governance / CI

| Requirement | Status | Automated evidence |
| --- | --- | --- |
| Future-change shared-runtime parity rule | PASS | `docs/architecture/APP_PREVIEW_ARCHITECTURE.md` and `SYSTEM_ARCHITECTURE.md` require Dashboard-managed app-visible changes to update real app/shared runtime and parity coverage in the same PR. |
| Repository Policy | PASS | Required CI always runs `./scripts/validate-repo.sh` plus branch policy; #571 acceptance PR is expected to execute it again. |
| Backend affected tests | PASS | #571 dedicated acceptance workflow groups session, Dashboard, Customer/Driver read bridge, revision, configuration, SSE and OpenAPI tests. |
| Customer Flutter + Web build | PASS | #571 dedicated acceptance workflow runs preview/shared journey tests and compiles Web preview. |
| Driver Flutter + Web build | PASS | #571 dedicated acceptance workflow runs preview/shared journey tests and compiles Web preview. |
| Required CI | PASS | #571 established the acceptance suite; #564/#573/#574/#575 each merged only after green required CI. This final #498 reconciliation PR re-runs Required CI on the combined `main` baseline before merge. |
| Visual/runtime QA | PASS | #575 / PR #580 makes embedded-vs-standalone real-runtime parity a CI failure condition; Admin visual QA remains applicable for Admin view changes. |

## Final reconciliation / closure decision

Repository-side APP-PREVIEW acceptance is complete on the combined final baseline.

Previously open gaps are now merged:
- #564 / PR #570 — Draft-first Retail/Wholesale Save Draft / Publish / Discard integration.
- #573 / PR #577 — scoped live invalidation consumption with authoritative runtime refetch.
- #574 / PR #579 — real device-profile viewport semantics. PR #578 was superseded and closed unmerged.
- #575 / PR #580 — automated embedded-vs-standalone visual/runtime parity gate.

This reconciliation PR intentionally changes acceptance documentation only so that `.github/workflows/app-preview-acceptance-ci.yml` and Required CI execute against the final combined `main` state. Merge is permitted only after those checks are green.

The deployed Customer/Driver Preview runtime URL/origin remains external deployment evidence. Repository acceptance does not fabricate that environment state; runtime-unavailable behavior is already explicit and tested. That external verification is not an open repository implementation gap under #498.

When this reconciliation PR is green and merged, #498 is eligible to close as completed.

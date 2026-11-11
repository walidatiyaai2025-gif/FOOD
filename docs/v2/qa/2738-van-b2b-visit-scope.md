# V2 #2738 / MOB-V02 — Van B2B-only visits and runtime scope

Source: P110/T115R04 from `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`. Parent #2503. All changes are isolated to `Foodex_V2`.

## Observed authority defect

The pre-change `VanVisitController::store` accepted `customer_type=b2c`, and `VanRuntimeVisitScope::query` authorized historic B2C visit rows by actor/van assignment without checking channel. This exposed B2C customer visits to Van route feed, customer lookup and lifecycle transitions. The Van mobile `HttpVanVisitRepository` also accepted B2C records as ordinary Van visits, regardless of the locked **B2B/Wholesale -> Van only; B2C/Retail -> Driver only** fulfillment policy.

## Bounded correction

- `VanVisitController` now validates only `b2b` on new visit creation, and rejects `b2c` customer detail lookups with 404.
- `VanRuntimeVisitScope` applies a B2B predicate before actor/van-assignment ownership. In a resolved live Van context, an actor's explicitly Van-tagged visits must match the current Van; legacy untagged visits from that actor remain supported. Current Van-tagged visits remain accessible by current Van scope. This prevents a reassigned operator reading or transitioning another Van's prior B2B visits in addition to excluding legacy B2C rows, including rows with the same `metadata.van_id`. All consumers of its query (visit feed, customer list/detail, transition authorization) inherit this isolation. No existing visits, uploads, PII or financial ledgers are deleted or rewritten.
- Explicit `metadata.van_assignment_id` must match the currently effective assignment, even when both assignments use the same Van. Historic untagged records retain their existing actor/Van compatibility restriction. Replayed idempotency keys require an existing B2B row belonging to the same authorized actor/customer and active Van assignment; otherwise HTTP 409. This closes legacy B2C, other-customer and prior-assignment replay paths without changing existing data.
- `HttpVanVisitRepository` accepts only authoritative `customer_type=b2b` records in list or transition responses. Invalid channel causes a typed API failure of the complete response rather than returning a misleading partial successful route feed.
- Existing Van login, token, app-scoped authorization, idempotency key, proof/visit status transitions, required evidence, collection and remittance implementations are left intact.

## Automated acceptance

Backend: `backend/tests/Feature/VanVisitControllerTest.php` uses an authenticated, assigned Van operator and proves same-Van different-assignment visits are invisible and unmodifiable, and idempotency replay of legacy B2C, a different B2B customer or a prior assignment fails with 409 while identical authorized replay succeeds. It also proves B2B route feeds, that legacy B2C visits are hidden even for the original actor, that B2C customer lookup/transition returns 404, that B2C creation returns validation failure and that no unauthorized visit is created. Existing legacy-route metadata regression now uses a legitimate B2B visit.

Flutter: `apps/van_app/test/van_visit_scope_contract_test.dart` uses hermetic `MockClient` fixtures for B2B route response/real endpoint and bearer token, mixed B2B/B2C feed fail-close, and B2C transition response rejection.

**Impact matrix:** Backend Van Visit API changed (intentional B2B policy tightening). Van app parser changed, no additional UI strings or screens; the existing route/error UI maintains AR/EN/RTL and shows its localized generic error state. Customer app: no owned code or API change; wholesale customer visits remain in Van. Driver app: no code/API changed; B2C delivery remains Driver-only. Dashboard: no admin route/permission changed; server-side Van API scope is stronger. B2B store/territory access still needs wider source acceptance audit.

**Validation**: Local Flutter/Composer toolchains were not used in this GitHub Connector execution. GitHub exact-head V2 Isolation, policy, AR/EN, Laravel and Flutter CI are required before any merge. No green claim until terminal checks.

## Remaining scope — issue intentionally not closed by this bounded fix

MOB-V02 also requires exhaustive checks across assignment state changes, route/territory polygon membership, stale/expired Van assignment, cross-representative van metadata, visits' location provenance, session revoke, Supervisor permissions and complete AR/EN/small-screen E2E. Cross-app final release validation and owner UAT remain governed by #2503. Source-level missing behaviors must not be deferred or treated as complete.

**Forbidden**: no changes to main, live deployment, APK/tag/release, user data migration or issue closure before source-complete validation.

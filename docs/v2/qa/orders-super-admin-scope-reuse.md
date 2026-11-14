# FOODEX V2 #3416 — Orders Super Admin scope query reuse

## Source, ownership and placement
- Parent: #3160 (Enterprise Performance exact-head pair); umbrella: #2503.
- Only integration target: `Foodex_V2`; no `main`, release, tags, APK builds or production.
- Source: `OrderOperationsController::index` / `OperationalTenantScope::allowedStoreIds` on the isolated branch. The latter returns all active stores of the requested channel for **active SUPER_ADMIN** independent of its permission argument. Unprivileged actors use an entirely different permission-scoped path with effective deny filtering.
- Existing UI: Operations → Orders (`admin.operations.orders.index`), standard workbench grid and review tabs. Decision: **Reuse**. No new route, navigation, UI component, view or form.
- Persona: active Super Admin; read-only list, scoped controls. Other roles retain their own per-action scope computations. Existing `channel=all` denial still applies to all users, including Super Admin.
- Canonical authorization continues in `OperationalTenantScope`, controller's channel/store checks and all existing action-specific mutation endpoints. Only the repeated **identical** scope result in the same `index` request is reused; there is no persistent cache or sharing across actors/requests.

## Data / RBAC / UI contract
- Source of truth: active stores and store types through `OperationalTenantScope`. The precomputed result is reused for manage, dispatch, finance, discount-request and discount-approval **only when the same actor passes the active SUPER_ADMIN condition**.
- Non-Super Admin actors including scoped Retail/Wholesale operators, inactive users and explicit-DENY scopes retain the original independent permission queries. The optimization is not a new permission grant.
- The pending/approved discount tabs and their own authorization intersection are preserved. B2B / B2C list isolation, existing 404 for foreign store, 403 for disallowed channels, and exact-order POST authorization remain unchanged.
- Arabic and English remain in the existing locale-aware view, with RTL/LTR, responsive Orders grid, compact actions and filter state unchanged. No new text/UI labels/keys.
- Customer app: **not affected**, no public API or Flutter changes.
- Driver app: **not affected**, B2C Driver-only assignment contract unchanged.
- Van app: **not affected**, B2B Van-only fulfillment unchanged.

## Verification and limitations
- New `V2OrderOperationsSuperAdminScopeReuseTest`: seeded Super Admin GET in EN/AR, discount tabs, mixed-channel HTTP 403 and unprivileged HTTP 403.
- Existing Orders authorization and Enterprise Performance smoke coverage are required at exact PR head; compare actual numerics rather than assuming a p95 improvement from fewer duplicate store-list reads.
- Benchmark-only `#3160/#3171` provenance/source-anchor approval remains independent. This branch does not alter comparator, history, performance limits, CI, fixtures or release code.
- **Evidence status:** final exact-head Repository Policy, V2 Isolation, Required CI and any performance benchmark conclusions must be attached after real completion. Until then, this note is design/test coverage, not a passing CI or deployment claim.

## Reproducible executed-SQL query-count benchmark

- The updated `V2OrderOperationsSuperAdminScopeReuseTest` registers a Laravel `DB::listen` callback around actual authenticated Orders HTTP GETs for an active Super Admin in **English and Arabic**. A synchronous call-stack filter attributes only real `OperationalTenantScope::storeIdsForChannels` SQL executions invoked via `OrderOperationsController::operationalScopes` to this benchmark; unrelated Sidebar/store queries are excluded.
- The test asserts **exactly 2 canonical store-scope SQL executions for 2 authorized B2C requests**, or **1 per request**. The original `index` path made 6 identical `operationalScopes` calls (view, manage, dispatch, finance, discount request, discount approval) for an active Super Admin. Under the canonical permission-independent Super Admin scope, this removes **5 redundant scope lookup SQL executions per B2C Orders request**. This describes only the measured/tested query category, not total page SQL.
- Authorization remains tested in the same fixture: mixed-channel request 403, roleless user 403, EN/AR discount tabs accessible. Other actors' per-permission scopes remain unchanged, and the optimization does not affect writes or cross-store grants.
- This is a deterministic **query-count performance regression**, not a wall-clock/p95 result. The separate Enterprise Performance certification and immutable historical baseline provenance in #3160 remain independent. The query-count test and all required latest-HEAD checks must pass before integration; no staging/production claim.

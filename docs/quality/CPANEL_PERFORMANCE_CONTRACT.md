# FOODEX cPanel Performance Contract

Status: **Authoritative Enterprise release gate**
Issue: #1482
Applies to: all Enterprise modules and any shared infrastructure they touch

This contract makes performance and cPanel operability first-class release requirements.

## 1. Runtime principle

FOODEX already has an established Laravel scheduler/cPanel contract under `docs/operations/PROMOTIONAL_NOTIFICATION_SCHEDULER.md`. Enterprise work must reuse it.

New modules may not require a new always-on Python/Node/Java process, new microservice, privileged systemd unit, or a second scheduler as a condition for FOODEX core operation.

Optional high-scale profiles may use additional infrastructure when explicitly configured, but the baseline product must remain deployable through the supported FOODEX/cPanel path.

## 2. Supported performance profiles

### Profile A — cPanel baseline

Assumptions:

- PHP/Laravel web runtime under the existing hosting model;
- MySQL/MariaDB compatible with the existing FOODEX deployment;
- existing FOODEX scheduler contract;
- existing configured queue/cache services may be reused;
- no assumption that Supervisor/systemd/root access is available;
- bounded scheduled/short-lived workers are allowed;
- module correctness does not depend on a persistent daemon.

### Profile B — accelerated

Optional accelerators may include:

- Redis cache/queue;
- persistent queue workers where hosting supports them;
- read replica/reporting database;
- CDN/object storage;
- dedicated worker hosts.

Profile B may improve throughput. It may not change business correctness or authorization semantics.

## 3. Hard architectural prohibitions

The following block promotion:

1. unbounded database reads on user-facing paths;
2. N+1 query patterns on critical/high-volume screens;
3. external HTTP/provider calls inside an open DB transaction;
4. synchronous generation of large exports on the request path;
5. synchronous full-history analytics recomputation on the request path;
6. per-request table scans introduced by missing indexes;
7. mandatory always-on daemon requirement added by an optional module;
8. loading all module JavaScript/CSS on every page when the module is disabled or not in use;
9. a disabled module registering expensive global boot work;
10. retry loops without backoff/upper bound;
11. jobs that cannot resume safely after timeout;
12. caches whose keys omit tenant/store/user scope where that scope affects the result;
13. “fixing” performance by weakening authorization or tenant isolation.

## 4. Release performance gates

Benchmarks must be run on the exact promotion head with a seeded representative dataset and the same scenario set as the stored baseline.

### 4.1 Relative regression gate

For a changed critical route/journey:

- p95 server time must not regress by more than **15%** versus the accepted baseline;
- p99 server time must not regress by more than **20%**;
- query count must not regress by more than **15%** unless the added queries are explicitly justified and indexed;
- peak request memory must not regress by more than **15%** without explicit architecture approval.

A regression beyond these limits is a release blocker even when the absolute response is still “fast”.

### 4.2 Absolute guardrails

On the cPanel reference environment, excluding third-party network latency:

| Workload | Guardrail |
|---|---:|
| Warm lightweight authenticated read | p95 <= 1.0 s |
| Standard paginated CRUD/list/detail | p95 <= 1.5 s |
| Standard transactional write | p95 <= 1.8 s |
| Dashboard composition | p95 <= 2.0 s |
| Interactive bounded report | p95 <= 2.5 s |
| Provider-independent checkout/order commit | p95 <= 1.8 s |

Shared-host noise is handled by at least three benchmark rounds; use the median p95 across rounds.

A route that legitimately cannot meet a guardrail requires an explicit exception in its Issue with measured evidence and an asynchronous/UX mitigation plan.

## 5. Database rules

### 5.1 Pagination

- default page size: 25 unless a canonical FOODEX surface defines another value;
- normal maximum page size: 100;
- high-volume endpoints must use server-side pagination;
- cursor/keyset pagination should be preferred where offset cost becomes material;
- never expose “all rows” on large operational tables.

### 5.2 Query design

Critical lists and dashboards must:

- select only required columns where practical;
- eager-load bounded relations;
- avoid nested per-row count/sum queries;
- aggregate in SQL/read models rather than PHP loops over large datasets;
- have indexes matching common tenant/store + filter + sort predicates;
- include tenant/store keys early in composite index design where appropriate.

### 5.3 Slow query policy

For critical routes:

- queries repeatedly exceeding 250 ms on the reference dataset require investigation;
- new/changed expensive queries require `EXPLAIN` evidence in the owning Issue/PR;
- a full table scan on a growing business table is a blocker unless the table is intentionally small/static and documented.

### 5.4 Index policy

Every new high-growth table must define expected access patterns before migration review.

Typical growth tables include:

- orders/order items;
- stock movements;
- journal lines;
- payments;
- outbox/events;
- audit events;
- WhatsApp messages/events;
- inventory snapshots;
- report facts.

Indexes must be justified by real read/write paths; do not add speculative duplicate indexes.

## 6. Memory and payload budgets

- benchmark request peak memory must stay below **75% of the configured PHP memory_limit**;
- target normal request peak memory: <= 96 MiB;
- a single background job slice should target <= 128 MiB;
- jobs/exports that exceed the target must chunk and checkpoint;
- normal JSON responses should remain bounded; large datasets use pagination/export paths;
- binary/file downloads stream instead of loading the full file into PHP memory where feasible.

## 7. Cache policy

Caching is allowed only with explicit correctness semantics.

Every cache must define:

- key;
- tenant/store/user scope;
- TTL;
- invalidation trigger;
- stale behavior;
- fallback when cache is unavailable.

Good cache candidates:

- permission/entitlement metadata;
- small stable lookups;
- compiled dashboard summaries;
- read-mostly configuration;
- expensive deterministic report fragments with scoped keys.

Do not cache mutable financial/inventory authority in a way that can return incorrect transaction decisions.

A cache outage must not bypass authorization.

## 8. Queue/background policy

### 8.1 Business correctness

Core business transactions commit without waiting for:

- WhatsApp/SMS/email delivery;
- BI refresh;
- large export generation;
- provider synchronization;
- non-critical webhooks.

These side effects use the existing queue/scheduler contracts and idempotent jobs.

### 8.2 cPanel worker shape

Where persistent workers are unavailable, Enterprise jobs must support bounded short-lived consumption driven by the existing scheduler/cron deployment.

Job design requirements:

- idempotent;
- retry-safe;
- explicit timeout;
- small chunk;
- resumable cursor/checkpoint for large workloads;
- no assumption of process-local state;
- no job that must run longer than a hosting execution limit.

Target slice runtime: <= 45 seconds for cPanel-safe jobs.

Long tasks must split into multiple slices.

### 8.3 Queue backlog

Module Health must expose:

- pending count;
- failed count;
- oldest pending age;
- last successful execution;
- last error summary.

A queue backlog in one optional module must not block unrelated module requests.

## 9. Scheduler policy

Enterprise modules must register tasks through the existing Laravel scheduler path.

Rules:

- do not add a second independent cron system;
- use overlap protection for non-reentrant scheduled work;
- schedule work in small batches;
- avoid running all expensive module jobs at the same minute;
- randomize/stagger non-time-critical maintenance windows where deterministic scheduling permits;
- a missed run must be recoverable on the next run.

## 10. External provider policy

HTTP clients require:

- connect timeout;
- request timeout;
- bounded retries;
- exponential/backoff strategy where appropriate;
- idempotency for writes if provider supports it;
- circuit-breaker/backoff after repeated failure;
- structured failure logging without secrets.

Provider latency is excluded from core transaction performance budgets because provider work should not be on the core commit path.

## 11. Reporting and exports

### 11.1 Interactive reports

- default bounded period must be reasonable for the report;
- high-volume reports require explicit date/tenant filters;
- pre-aggregated/read-model paths are preferred for executive dashboards;
- drill-down uses canonical paginated records.

### 11.2 Exports

Exports expected to exceed 5,000 rows must be asynchronous/queued or chunk-streamed according to the current deployment capability.

Export jobs must:

- authorize at creation;
- preserve requester/tenant/store scope;
- snapshot filter parameters;
- avoid retaining raw secrets;
- expire generated files according to policy;
- expose status/failure.

## 12. Frontend/UI performance

Enterprise modules must:

- avoid shipping module-specific heavy assets globally;
- lazy-load charts/editors/builders where practical;
- paginate data grids;
- debounce server-side search;
- cancel/ignore stale search requests;
- avoid expensive DOM rendering of thousands of rows;
- use image sizing/lazy loading;
- preserve responsive behavior at supported breakpoints.

A new module should not increase unrelated dashboard initial JS/CSS payload by more than 10% without explicit approval.

## 13. Dashboard composition

Dashboard cards/widgets must not execute one query per widget per row.

Preferred order:

1. bounded aggregate query;
2. precomputed/read model;
3. scoped cache with explicit invalidation;
4. drill-down for detail.

A user without entitlement to a module must not pay the module’s dashboard query cost.

## 14. Outbox/event performance

Outbox consumers process bounded batches.

Requirements:

- producer insert is lightweight and transactional;
- consumers are idempotent;
- completed events are retained/archived according to operations policy;
- indexes support “pending oldest first” access;
- poison events do not block the entire queue;
- cleanup is scheduled and chunked.

## 15. Audit performance

Audit remains mandatory but must not create unbounded synchronous payloads.

Store:

- actor;
- action;
- entity;
- before/after relevant fields;
- scope;
- timestamp;
- provenance.

Avoid storing giant binary/document payloads or repeated full object graphs in audit rows.

## 16. Performance test dataset

Certification must include representative scale, not empty-database tests.

Minimum reference fixture for Enterprise certification should model at least:

- 100,000 orders;
- 500,000 order items;
- 250,000 stock movements;
- 100,000 payments/invoices combined where applicable;
- 250,000 audit/events/messages for event-heavy modules;
- multiple organizations/stores/warehouses;
- enough products/customers/suppliers to exercise real filters.

A module may define a larger fixture when its expected production scale requires it.

## 17. Performance evidence required in PR

Every Enterprise module promotion PR must include:

- exact head SHA;
- environment/profile;
- seeded dataset size;
- critical journeys benchmarked;
- p50/p95/p99;
- query count;
- slowest queries;
- peak memory;
- baseline comparison;
- queue/backlog behavior if applicable;
- any accepted exception and owner.

“No noticeable slowdown” is not evidence.

## 18. cPanel deployment checks

Before promotion:

- existing update path completes;
- migrations complete within safe deployment windows or are chunked/deferred;
- `config:cache` succeeds;
- `route:cache` succeeds where the current release contract requires it;
- `view:cache` succeeds;
- scheduler health is green;
- module does not require manual root/supervisor/systemd work;
- disabled module does not perform recurring expensive work;
- module health page identifies degraded dependencies.

## 19. Performance rollback trigger

After pilot/GA, rollback/disable is required when a new module causes any of:

- sustained p95 > 2x accepted baseline on a critical route;
- DB saturation attributable to the module;
- queue backlog growing without recovery;
- memory exhaustion/fatal errors;
- lock contention/deadlocks beyond accepted baseline;
- cross-module availability degradation.

Because modules are independently controlled, the preferred first response is to disable/suspend the affected module while preserving its data and the rest of FOODEX.

## 20. Definition of PASS

Performance is PASS only when:

- no hard prohibition is violated;
- relative regression gates pass;
- applicable absolute guardrails pass or have an approved measured exception;
- database access is indexed/bounded;
- queue/scheduler behavior is cPanel-safe;
- failure isolation is proven;
- disabled-module overhead is negligible;
- exact-head evidence is attached to the certification Issue/PR.

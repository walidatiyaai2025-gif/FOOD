# Current-main Enterprise Performance Baseline

Issue: #1544  
Parent: #1488

This lane records the first numeric regression anchor for the FOODEX application source verified as
current `main` immediately before the baseline capture:

`d171df2961687455f08c14b9419df83027edcbf0`

The smoke baseline intentionally exercises real product routes, not synthetic benchmark-only routes:

| Scenario ID | Route | Workload class |
| --- | --- | --- |
| `admin.dashboard` | `GET /admin` | dashboard |
| `admin.orders.index` | `GET /admin/operations/orders?...&per_page=25` | paginated_crud |
| `admin.reports.orders` | `GET /admin/reports?report=orders...&per_page=25` | bounded_interactive_report |

Each scenario uses the shared runner from #1503, at least three measured rounds, and the deterministic
smoke fixture from #1502. The emitted JSON uses the #1514 evidence contract and includes p50/p95/p99,
median-round p95, query count, normalized slowest-query evidence, and peak PHP memory.

The test writes:

`storage/app/private/enterprise-performance/current-main-smoke-evidence.json`

and emits the same document to the test log with the marker `FOODEX_PERF_EVIDENCE_JSON=`.

This evidence has `cpanel_reference=false`. It is a relative regression anchor only. It must not be
used to claim the absolute cPanel latency guardrails. Final promotion still requires the representative
heavy dataset, MySQL/MariaDB evidence, cPanel deployment smoke, and the dedicated cPanel reference
certification lanes.

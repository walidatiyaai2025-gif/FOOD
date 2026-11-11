# V2 #3160 — Enterprise Performance same-runner certification

Parent #2503; prerequisite to #2858 (PR #3065). Integration target **Foodex_V2 only**.

## Root cause and source provenance

Immutable archived benchmark `d171df2961687455f08c14b9419df83027edcbf0`
was recorded with PHP 8.3.35 and SQLite 3.45.1. Current hosted run is
PHP 8.3.36 and SQLite 3.46.1. A strict comparator correctly fails
the environment-fingerprint mismatch; simply ignoring SQLite version or
suppressing that CI job would invalidate the performance gate.

The archived source itself predates the current
`CurrentMainPerformanceBaselineTest`, so the archived JSON cannot be
re-measured in the current test harness without misrepresenting its lineage.
The reviewed V2 source anchor selected at the start of this fix is
`d37204b58cf6ee604420f87bdfaf5f7d20c8f4c6`.
The anchor is fixed, ancestral to candidate source and measured as a **real**
baseline against the actual same-runner candidate on every PR, not forged
from an unrelated archived artifact. Its introduction is reviewable in this
one issue-scoped PR; never rewrite historic benchmark evidence.

## Acceptance matrix

| Gate | Evidence / expected behavior |
| --- | --- |
| Exact checkout | Candidate and immutable source anchor SHAs validated; anchor must be ancestor |
| Runner parity | Both benchmarks run within one job with one PHP/SQLite installation |
| Isolation | Distinct source checkout, Composer dependencies, .env, DB and benchmark outputs |
| Evidence lineage | Baseline mode baseline_head=null, source/harness SHA=anchor; candidate points to anchor |
| Dataset | Equal actual seeded inventory/order/finance fixture counts required |
| Missing/incompatible source | FAIL closed, no fallback to stale historical JSON |
| SQLite drift | Comparator regression rejects 3.45.1 → 3.46.1, preserving strictness |
| Real regressions | p95, p99, query count, memory regression tests must fail |
| cPanel limits | Absolute limits unchanged, cannot be bypassed by exceptions |
| Release readiness | This is smoke certification only; no production/UAT or G0–G13 ERP attestation |

## App impact

- Backend: only CI benchmarking and Python validation; no Laravel runtime,
  HTTP response, database schema/migration/seed, or production configuration change.
- Dashboard: no Blade, route or sidebar runtime change.
- Customer App: NOT AFFECTED — no API, token, pricing or Flutter change.
- Driver App: NOT AFFECTED — no dispatch, authorization, API or Flutter change.
- Van App: NOT AFFECTED — no B2B routing, assignments, API or Flutter change.
- B2B = VAN ONLY; B2C = DRIVER ONLY remains unaffected.

## Required evidence

Capture exact-head Repo Policy, V2 Isolation, Required CI, Enterprise
Performance job and its paired baseline/candidate/comparison artifact URLs
in Issue #3160. Do not close before independent baseline-source review,
green exact-head CI and `Foodex_V2`-only PR merge. No main, release,
tag, production or cPanel deployment.

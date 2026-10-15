# Enterprise HTTP/Journey Benchmark Runner

Issue: #1503  
Parent: #1488

`Tests\\Performance\\Support\\HttpJourneyBenchmarkRunner` is the reusable in-process Laravel
measurement runner. It reuses the shared percentile, query-count, slow-query, and memory probes
instead of creating another benchmark stack.

Profiles always execute at least three measured rounds. Warmup requests are excluded from evidence.
Each measured sample captures:

- server-side journey duration using `hrtime`;
- DB query count;
- normalized slow-query fingerprints and query time;
- PHP peak memory.

The runner reports p50/p95/p99 across all samples plus the **median p95 across rounds**, which is the
cPanel shared-host noise rule in `CPANEL_PERFORMANCE_CONTRACT.md`.

This runner measures Laravel application work in-process and is suitable for repeatable relative
baseline/candidate comparison. Absolute cPanel web-server guardrails require the dedicated cPanel
reference smoke/certification lane (#1513); CI-local timings must not be mislabeled as production
cPanel latency.

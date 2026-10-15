# Enterprise Performance Evidence Format

Issue: #1514  
Parent: #1488

Every Enterprise performance run produces one canonical JSON document validated against
`docs/quality/ENTERPRISE_PERFORMANCE_EVIDENCE.schema.json`. A human-readable Markdown report is
rendered from that same JSON by `scripts/enterprise-performance-report.py`; the Markdown is never
a second source of truth.

Required lineage:
- `source_head`: exact application source SHA being certified;
- `harness_head`: exact SHA containing the benchmark harness;
- `baseline_head`: accepted baseline source SHA when doing regression comparison;
- `profile`: smoke, standard, heavy, or cPanel.

Required dataset evidence includes counts for orders, order items, stock movements, finance records,
audit/events/messages, stores, warehouses, and tenants. Zero is allowed only when the domain is
explicitly unavailable to that profile; final Enterprise heavy certification must satisfy the
authoritative scale in `CPANEL_PERFORMANCE_CONTRACT.md`.

Every scenario records workload class, rounds, p50/p95/p99, median round p95, query count, slowest
queries, peak memory, optional baseline/queue evidence, and a PASS/FAIL/WARN verdict with reasons.

Artifacts must not contain credentials, tokens, raw PII bindings, or provider secrets. SQL evidence
uses normalized SQL/fingerprints without raw bindings.

Recommended artifact paths:
- `artifacts/enterprise-performance/performance-evidence.json`
- `artifacts/enterprise-performance/performance-report.md`

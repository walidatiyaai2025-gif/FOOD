# Enterprise Performance Baselines and Regression Gates

Issue: #1540  
Parent: #1488

The performance gate uses versioned JSON evidence from #1514. Threshold logic is implemented once in
`scripts/enterprise-performance-compare.py`; CI (#1515) calls this evaluator rather than copying
percentages into workflow YAML.

## Relative gates

The stable p95 gate compares **median round p95** between baseline and candidate.

- p95 >15% regression: FAIL unless an approved scoped exception exists;
- p99 >20% regression: FAIL;
- query count >15% regression: FAIL unless an approved scoped exception exists;
- peak PHP memory >15% regression: FAIL unless an approved scoped exception exists.

An approved exception must include `approved=true`, `approved_by`, `reason`, `review_by`, and
the explicit `metrics` it covers. p99 and absolute cPanel guardrails are not suppressed by this
exception mechanism.

## Absolute cPanel p95 guardrails

Only evidence marked `environment.cpanel_reference=true` applies absolute web-server limits:

| Workload class | Max median-round p95 |
| --- | ---: |
| lightweight_authenticated_read | 1000 ms |
| paginated_crud | 1500 ms |
| transactional_write | 1800 ms |
| dashboard | 2000 ms |
| bounded_interactive_report | 2500 ms |
| provider_independent_order_commit | 1800 ms |

Captured query fingerprints at >=250ms are emitted as investigation warnings even if the scenario
passes its latency gate.

## Immutable baseline storage

Accept a baseline with:

```bash
python3 scripts/enterprise-performance-compare.py accept evidence.json
```

The default repository path is:

`benchmarks/enterprise/baselines/<environment-fingerprint>/<profile>/<source-head>.json`

The source SHA and environment fingerprint are part of the identity. An existing baseline is a no-op
only when bytes are equivalent after canonical rendering; divergent content at the same identity is
rejected instead of silently overwritten.

Compare:

```bash
python3 scripts/enterprise-performance-compare.py compare baseline.json candidate.json --output result.json
```

Exit code 1 means the promotion gate failed.

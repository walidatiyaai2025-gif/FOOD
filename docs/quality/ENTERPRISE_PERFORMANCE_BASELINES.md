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

## V2 same-runner smoke source pairing (#3160)

The historical accepted smoke evidence at `d171df2961687455f08c14b9419df83027edcbf0`
is **not edited, overwritten, or silently compared across incompatible environments**.
Its fixed PHP 8.3.35 / SQLite 3.45.1 fingerprint must not be treated as
comparable with a different SQLite version.

For V2 smoke CI, the source anchor pinned by
`.github/workflows/required-ci-gate.yml` is
`d37204b58cf6ee604420f87bdfaf5f7d20c8f4c6` (reviewed V2 integration
source before #3160). On each candidate CI job, check out this exact immutable
Git SHA and the candidate SHA separately; require the anchor to be an ancestor,
install both lockfiles, run the same benchmark on the same GitHub Actions worker,
PHP interpreter and SQLite runtime with independent DB/keys, and preserve
both JSON reports as artifacts. Never rewrite the old baseline JSON or fabricate
its measured values. The pin is a proposed separately reviewed baseline-source
change in #3160; future anchor changes require their own review and verification.

The acceptance comparator continues to enforce p95 15%, p99 20%, query
count 15%, peak memory 15%, and all applicable cPanel absolute ceilings,
without allowing exceptions to p99 or cPanel. An exact runtime fingerprint
(including database version) and equal fixture dataset are required for the
paired job; mismatches **FAIL**. The separate comparator still rejects SQLite
minor-version drift, even when GitHub PHP patch drift is tolerated for
legacy comparison purposes. The paired evidence uses unchanged live
measurement, not copied historical numbers.

This is a smoke-only gate; heavy/cPanel benchmarks and independent ERP
workflow attestation remain separate mandatory release checks.

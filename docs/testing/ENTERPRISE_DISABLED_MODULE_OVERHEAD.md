# Enterprise Disabled-Module Overhead Gate

Issue: #1511  
Parent: #1488  
Wave 0 consumer: #1487 / #1527

This gate proves that an Enterprise module in `DISABLED` state does not impose hidden runtime work
on unrelated FOODEX traffic.

It deliberately reuses `scripts/enterprise-performance-compare.py` for the authoritative relative
thresholds:

- median-round p95 regression <= 15%;
- p99 regression <= 20%;
- query-count regression <= 15% unless an approved comparator exception exists;
- peak-memory regression <= 15% unless an approved comparator exception exists.

The candidate evidence must additionally include:

```json
{
  "module_off": {
    "state": "DISABLED",
    "module_keys": ["enterprise.sample"],
    "module_specific_global_queries": 0,
    "module_specific_boot_work_count": 0,
    "recurring_work_count": 0,
    "provider_calls": 0
  }
}
```

All four work counters are hard zero while the module is OFF. They cannot be waived by a normal
performance exception because OFF means the module may not perform material global/recurring work.

## Measurement contract

Run the same canonical baseline journeys in the same profile/environment:

1. accepted current-main baseline;
2. composed candidate head with the target Enterprise module installed but explicitly DISABLED.

The candidate sets `baseline_head` to the accepted baseline source SHA and records its exact
candidate/harness heads. The generic comparator checks route regressions; this gate then checks the
module-OFF work counters.

For Wave 0, #1527 owns execution against the composed `feature/enterprise-core-platform` head.
Any non-zero disabled-module global query, boot-work, recurring-work or provider-call count is a
#1487 blocker even when wall-clock latency remains below an absolute guardrail.

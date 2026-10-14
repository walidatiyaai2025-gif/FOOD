# FOODEX 1.0.77 Worker Coordination

Parent: #1380
Integration branch: `release/1.0.77-production-program`

## Universal instructions for every worker

1. Refresh the latest integration branch before starting.
2. Re-read your canonical issue.
3. Race-check active branches/PRs for the exact same issue.
4. Create one dedicated branch for your issue.
5. Stay inside your exclusive lane.
6. Do not change `apps/customer_app/**`.
7. Add focused tests/evidence for the exact behavior you own.
8. Rebase/refresh from integration immediately before PR handoff.
9. Resolve conflicts without taking ownership of another lane.
10. PR back to `release/1.0.77-production-program`, not directly to main.
11. Do not claim completion on placeholders, static UI, fake responses, or documentation-only evidence.
12. Do not remove the production hold.

## Absolute Customer freeze

Baseline: `4de5a24b2ca6bd9b448a79ca05f7f44c7d428f4d`

Before handoff run an equivalent of:

```bash
git diff --exit-code 4de5a24b2ca6bd9b448a79ca05f7f44c7d428f4d -- apps/customer_app
```

Any diff is a hard failure.

## Suggested branch names

- #1381: `worker/1381-customer-freeze-release-contract`
- #1382: `worker/1382-launch-image-fidelity`
- #1383: `worker/1383-catalog-provenance`
- #1384: `worker/1384-snapshot-restore`
- #1385: `worker/1385-dashboard-data-switch`
- #1386: `worker/1386-launch-gate-report`
- #1387: `worker/1387-release-1-0-77`
- #1388: `worker/1388-final-certification`

## Merge ordering

Preferred integration order:
1. #1381 release/freeze contract
2. #1383 provenance contract and #1384 snapshot service (parallel-safe if files do not overlap)
3. #1382 image manifest/assets
4. #1385 Dashboard switch
5. #1386 launch gate/report
6. #1387 release composition
7. #1388 certification

This ordering is guidance, not permission to ignore active ownership/race checks.

## Required final evidence

Worker 8 must prove:
- zero Customer diff;
- old-data content fingerprints before trial == after revert;
- complete inferred-value report;
- complete image manifest;
- switch state correctness;
- successful second launch after revert;
- exact source SHA evidence.

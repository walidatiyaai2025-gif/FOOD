# FOODEX 1.0.114 Synchronized Release

## Scope

- Self-healing Firebase device registration for Customer, Driver and Van after reinstall, token refresh, transient registration failure and app resume.
- Driver push registration retries with bounded backoff while preserving explicit logout revocation.
- Customer guest/authenticated push registration retries with diagnostics and resume recovery.
- Van push registration retries with resume recovery.
- Driver Home delivery workload card is 20% taller while the Home remains non-scrollable.
- Driver Home adds a full-width unsettled collections card backed by the authoritative custody ledger.
- Driver wallet API exposes `unsettled_collections` explicitly as the amount collected by the Driver and still held in custody before Finance settlement approval.
- Dashboard Finance Support shows authorized Driver unsettled custody totals grouped by currency from the same ledger source.

## Release identity

- Dashboard / repository: `1.0.114`.
- Customer: `1.0.114+114`.
- Driver: `1.0.114+114`.
- Van: `1.0.114+114`.

## Validation

Publish only from the final merged source after required CI, synchronized mobile identity checks, all three Android builds, Dashboard Update Center bundle generation, Laravel Setup/fresh-install acceptance, checksums and release provenance checks pass.

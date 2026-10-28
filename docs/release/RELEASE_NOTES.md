# FOODEX 1.0.119 — Synchronized Release Notes

## Identity

- Dashboard / repository: `1.0.119`.
- Customer App: `1.0.119+119`.
- Driver App: `1.0.119+119`.
- Van App: `1.0.119+119`.

## Changes

- Driver B2C Wallet becomes a visible destination in the existing Home, Deliveries and Notifications bottom navigation.
- Wallet retains persistent bottom navigation to Home and Deliveries, and per-order collected and remaining amounts remain accessible through the collected summary.
- Home Wallet shortcut and custody/collections summary remain available.
- B2B remains isolated and runtimes without wallet capability do not present a dead Wallet navigation entry.
- Arabic/English navigation and narrow-screen/permission-safe regressions are included.
- Rebuild synchronized Customer, Driver, Van, Dashboard Update and Laravel Setup from one exact final tested implementation source.

## Release quality gate

The release is not published until Required CI and the canonical Release Factory pass from the final `main` SHA, the Laravel Setup ZIP passes clean-install acceptance, APKs and Dashboard Update assets match published source/versions/SHA256, and the release registry is synchronized to protected main. Issue #2451 owns complete closure; this PR must not publish the release from its unmerged branch.

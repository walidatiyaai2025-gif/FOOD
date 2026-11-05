# FOODEX 1.0.123 — Synchronized Release Notes

## Identity

- Dashboard / repository: `1.0.123`.
- Customer App: `1.0.123+123`.
- Driver App: `1.0.123+123`.
- Van App: `1.0.123+123`.
- Existing published v1.0.121 and v1.0.122 tags, binaries and release assets remain immutable.

## Changes

- Integrate the approved owner commerce and operations changes from #2470 / PR #2471 into protected main, including B2B minimum-order controls (initial 1,500 EGP; zero disables), stock-only storefront setting, canonical selling-unit cart/checkout handling and order-payment visibility.
- Correct Customer/Driver UI and navigation, B2B/B2C Flash Offers product discovery, printing and FOODEX invoice identity, driver proof-of-delivery flows and related backend authorizations.
- Resolve Customer iOS Simulator CocoaPods / GoogleDataTransport conflicts and replace the barcode scanner iOS backend with the compatible stable native module; both Customer and Driver simulator build validation passed on merged PR source.
- Preserve B2B Van-only and B2C Driver-only assignment contracts, tenant/RBAC separation, live customer data and existing installer/updater protections.

## Release quality gate

Only the canonical FOODEX Release Factory triggered from the final protected-main version-promotion SHA may publish `v1.0.123`. Require exact-head GitHub Actions success, synchronized Customer/Driver/Van APKs, clean Laravel Setup ZIP installation, cumulative Dashboard Update ZIP with manifest and SHA-256, and protected-main release-registry synchronization. The update must accept installed Dashboard `1.0.121` via its declared minimum-compatible-version contract; application of any ZIP must first verify its integrity and trusted provenance and create a restorable backup. Do not treat the GitHub merge alone as deployment evidence.

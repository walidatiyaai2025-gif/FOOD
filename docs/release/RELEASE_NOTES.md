# FOODEX 1.0.65 Release Notes

Status: synchronized production release prepared from authoritative main after the post-1.0.64 FOODEX integration wave for Issue #1139.

Release candidate branch: `chore/1139-foodex-1-0-65-release`
Base main commit: `ee3e27e0a2c256eb7380bcabdaa77ea7c0652d1f`

## Release identity

- Dashboard / repository release: `1.0.65`
- Customer app: `1.0.65+65`
- Driver app: `1.0.65+65`
- Van app: `1.0.65+65`
- Customer runtime/footer identity: `1.0.65`
- Driver runtime/footer identity: `1.0.65`
- Production API: `https://foodex.50sols.com`
- Forbidden legacy API: `https://vanfoodex.50sols.com`

## Included convergence

- Publish all authoritative main changes merged after FOODEX 1.0.64.
- Include final Merchant Intelligence integration from #1122 / PR #1136 across Dashboard, Customer, Driver and Van journeys.
- Include batched authoritative B2B pricing, retail inventory price snapshots, validated Wholesale source-store lineage, reorder-query elimination and Dashboard cart-validation batching.
- Include the final integrated runnable product gate and query-stability coverage from the latest main source of truth.

## Release contract

- Customer, Driver and Van APKs must be built from the same final release source commit as the Dashboard Update and Laravel Setup package.
- `BUILD_INFO.json`, `LATEST_RELEASE.json` and `FRESH_INSTALL_EVIDENCE.json` must record that same source commit.
- APK binary verification must confirm `https://foodex.50sols.com` is embedded and `https://vanfoodex.50sols.com` is absent.
- GitHub Release `v1.0.65` must not be published until required PR checks are green.

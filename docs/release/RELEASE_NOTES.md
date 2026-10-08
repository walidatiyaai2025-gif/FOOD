# FOODEX 1.0.65 Release Notes

Status: synchronized production release prepared from the latest main convergence for Issue #1137.

Release candidate branch: `chore/1137-foodex-1-0-65-release`
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

- Promote all changes merged after FOODEX 1.0.64 through latest main `ee3e27e0a2c256eb7380bcabdaa77ea7c0652d1f`.
- Include the completed Merchant Intelligence integration from #1122 / PR #1136 across Dashboard, Customer, Driver and Van runtime journeys.
- Include authoritative Wholesale/Retail source lineage, batch B2B pricing and inventory snapshot improvements, reorder/query scaling fixes, and dashboard cart validation.
- Include integrated runnable product gates for Customer, Driver and Van plus the latest localization and repository-quality gates.
- Preserve the canonical production endpoint `https://foodex.50sols.com`.

## Release contract

- Customer, Driver and Van APKs must be built from the same final release source commit as the Dashboard Update and Laravel Setup package.
- `BUILD_INFO.json`, `LATEST_RELEASE.json` and `FRESH_INSTALL_EVIDENCE.json` must record that same source commit.
- APK binary verification must confirm `https://foodex.50sols.com` is embedded and `https://vanfoodex.50sols.com` is absent.
- Dashboard update must support upgrade from FOODEX 1.0.64 and must not require full redeploy unless generated manifest explicitly says so.
- GitHub Release `v1.0.65` must not be published until required PR checks are green.

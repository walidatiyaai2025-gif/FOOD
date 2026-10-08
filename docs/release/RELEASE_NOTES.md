# FOODEX 1.0.64 Release Notes

Status: synchronized production release prepared from the clean main baseline for Issue #1093.

Release candidate branch: `chore/1093-foodex-1-0-64-release`
Base main commit: `d42cf378e47138f8de76e24eb5d3cbbae781530b`

## Release identity

- Dashboard / repository release: `1.0.64`
- Customer app: `1.0.64+64`
- Driver app: `1.0.64+64`
- Van app: `1.0.64+64`
- Customer runtime/footer identity: `1.0.64`
- Driver runtime/footer identity: `1.0.64`
- Production API: `https://foodex.50sols.com`
- Forbidden legacy API: `https://vanfoodex.50sols.com`

## Included convergence

- Dashboard pagination coverage across the remaining grid-heavy surfaces, including geographic engineering and operational/admin lists.
- Viewport-safe three-dot action menus and compact grid interaction fixes.
- Retail-owner visibility in Flash Offer targeting with searchable lookup behavior.
- Geography reset/rebuild tooling and the complete Egypt FOOD Van geography dataset now present on main.
- All application and infrastructure changes already converged into the clean source-of-truth main SHA before this release candidate was created.

## Release contract

- Customer, Driver and Van APKs must be built from the same final release source commit as the Dashboard Update and Laravel Setup package.
- `BUILD_INFO.json`, `LATEST_RELEASE.json` and `FRESH_INSTALL_EVIDENCE.json` must record that same source commit.
- APK binary verification must confirm `https://foodex.50sols.com` is embedded and `https://vanfoodex.50sols.com` is absent.
- GitHub Release `v1.0.64` must not be published until required PR checks are green.

# FOODEX 1.0.67 Release Notes

Status: synchronized production release from the current authoritative `main` lineage.

## Release identity

- Dashboard / repository release: `1.0.67`
- Customer app: `1.0.67+67`
- Driver app: `1.0.67+67`
- Van app: `1.0.67+67`
- Customer runtime/footer identity: `1.0.67`
- Driver runtime/footer identity: `1.0.67`
- Production API: `https://foodex.50sols.com`
- Forbidden legacy API: `https://vanfoodex.50sols.com`

## Included convergence

- Publish the Van push-notification compatibility hotfix from #1166 while preserving Van/session isolation.
- Publish the Van B2B Product Catalog pricing fallback fix from #1168 using the canonical B2B price resolver.
- Preserve store, channel and customer scoping with regression coverage for base-price fallback and explicit tier price/MOQ/increment overrides.
- Include the current fresh-install acceptance hardening so Van assignment persistence checks tolerate current grid markup without weakening same-row Van/Territory validation.

## Release contract

- Customer, Driver and Van APKs must be built from the same final source commit as the Dashboard Update and Laravel Setup package.
- `BUILD_INFO.json`, `LATEST_RELEASE.json` and `FRESH_INSTALL_EVIDENCE.json` must record that same source commit.
- APK binary verification must confirm `https://foodex.50sols.com` is embedded and `https://vanfoodex.50sols.com` is absent.
- GitHub Release `v1.0.67` must not be published until required PR checks are green.

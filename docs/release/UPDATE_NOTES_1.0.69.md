# FOODEX 1.0.69 Release Notes

Status: immutable synchronized production release registered for Issue #1256 / PR #1257.

## Release identity

- Dashboard / repository release: `1.0.69`
- Customer app: `1.0.69+69`
- Driver app: `1.0.69+69`
- Van app: `1.0.69+69`
- Customer runtime/footer identity: `1.0.69`
- Driver runtime/footer identity: `1.0.69`
- Production API: `https://foodex.50sols.com`
- Forbidden legacy API: `https://vanfoodex.50sols.com`

## Included convergence

- Promote all authoritative main changes merged after FOODEX 1.0.67 into one synchronized production release.
- Include the B2B Van-only fulfillment cutover and associated Dashboard/Customer/Van operational convergence while preserving Driver-only Retail fulfillment.
- Include Customer low-data hardening that coalesces duplicate reads, caps foreground concurrency on weak links and prevents request storms before release.
- Include Dashboard-local APK mirroring and mobile-version policy decoupling so Customer, Driver and Van packages can be prepared and downloaded directly from the Dashboard backend with explicit mirror state.
- Include the one-push-green CI foundation, deterministic branch/release policy checks and Node 24 workflow migration.
- Include Customer app-wide responsive hardening for 320px devices, enlarged text, RTL/LTR footer behavior and narrow B2B/account surfaces.

## Release contract

- Customer, Driver and Van APKs must be built from the same final source commit as the Dashboard Update and Laravel Setup package.
- `BUILD_INFO.json`, `LATEST_RELEASE.json` and `FRESH_INSTALL_EVIDENCE.json` must record that same source commit.
- APK binary verification must confirm `https://foodex.50sols.com` is embedded and `https://vanfoodex.50sols.com` is absent.
- GitHub Release `v1.0.69` must not be published until required PR checks are green.

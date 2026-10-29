# FOODEX 1.0.120 — Synchronized Release Notes

## Identity

- Dashboard / repository: `1.0.120`.
- Customer App: `1.0.120+120`.
- Driver App: `1.0.120+120`.
- Van App: `1.0.120+120`.

## Changes

- Driver saved-session UX: fingerprint / biometric Sign in and Sign out only when a supported, remembered biometric session exists; no password/email fields or standard Sign in action in that state.
- Explicit Sign out securely forgets the saved login, attempts scoped server logout and returns to the regular email/password form.
- Repair secure Remember Me persistence for both authorized B2B and B2C drivers, with no password persistence and no new authentication backend APIs.
- Integrate shared Driver B2B/B2C wallet, premium data-driven Home/Wholesale Deliveries styling and RTL/LTR mobile responsive UI without changing order, finance or permission contracts.
- Update regression suites and visual evidence for both Driver channels in Arabic and English.
- Synchronously rebuild Customer, Driver and Van Android APKs, Dashboard update bundle and Laravel Setup from one exact final source commit.

## Release quality gate

This release is not published until Issue #2456 / PR #2459 is merged to protected main with successful required CI, this separate release-promotion PR has passing policy/CI, and the canonical `.github/workflows/release-factory.yml` produces and verifies all three 1.0.120+120 APKs, Dashboard update, Laravel Setup and clean-install evidence. Ensure `v1.0.120` is immutable, `BUILD_INFO.json` and `LATEST_RELEASE.json` agree with release assets and SHA256, generated distribution is synchronized, and protected-main Dashboard registry sync passes. Issue #2458 owns terminal closure.

# FOODEX 1.0.121 — Synchronized Release Notes

## Identity

- Dashboard / repository: `1.0.121`.
- Customer App: `1.0.121+121`.
- Driver App: `1.0.121+121`.
- Van App: `1.0.121+121`.

## Changes

- Forward-only recovery of the validated FOODEX 1.0.120 source at protected-main commit `b9c530b0944ba436503995084faf60a5537f228e`.
- Preserve the originally published immutable `v1.0.120` tag at commit `fb6f1924b00e31e63c09009e6f537d55672baae9`; never rewrite its release assets.
- Retain the B2B/B2C Driver Wallet, premium responsive Home / Deliveries interface, Remember Me encrypted session restoration, biometric-only saved-session unlock and Sign out from the previously validated 1.0.120 main source.
- Promote synchronized version identity to 1.0.121 without modifying product business rules, permissions, finance calculations, localization or login behavior.
- Rebuild all three Android APKs, Dashboard Update and Laravel Setup from the exact final 1.0.121 main commit and verify checksums / clean-install evidence.

## Release quality gate

Publish only after #2464's protected-main PR is exact-head CI green; require the canonical `.github/workflows/release-factory.yml` to produce and verify immutable `v1.0.121`, three APKs, Dashboard Update ZIP plus manifests, clean Laravel Setup and protected-main update registry. Do not mark #2464 or predecessor #2458 terminal before complete evidence. The historic 1.0.120 tag/Release remain unchanged.

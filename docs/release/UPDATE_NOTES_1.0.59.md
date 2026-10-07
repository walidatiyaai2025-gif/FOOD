# FOODEX 1.0.59 Release Notes

Status: terminal UIUX-V42 real release for Issue #1021. This release is built from the #1012 frozen implementation lineage and is not merged to `main`.

## Release identity

- Dashboard: `1.0.59`
- Customer: `1.0.59+59`
- Driver: `1.0.59+59`
- Van: `1.0.59+59`
- Production API: `https://foodex.50sols.com`

## UIUX-V42 convergence

- Customer, Driver and Van compact/full-width mobile layout acceptance.
- Remember Me, biometric unlock and login identity parity across all three mobile apps.
- Driver live new-order alert/deep-link behavior.
- Unified Dashboard live tracking for Drivers and Vans with truthful stale/offline handling.
- Real Customer commercial invoice presentation using authoritative backend values.
- Final Arabic/English, RTL/LTR and localized-data parity acceptance.

## Terminal release integrity

- The release workflow builds Customer, Driver and Van APKs, Dashboard update assets and `Release/FOODEX-Laravel-Setup.zip` from the same source commit.
- The exact generated Setup ZIP is clean-installed against empty MySQL/Redis state and validated through first-owner creation, installer lock/version state and logged-in Dashboard acceptance.
- `BUILD_INFO.json`, `LATEST_RELEASE.json` and `FRESH_INSTALL_EVIDENCE.json` retain the exact release source commit.
- The Setup ZIP and synchronized release artifacts are published to the immutable GitHub Release, generated distribution branch and `release/1021-uiux-v42-final-real-build`.
- Setup SHA-256 is verified against the published release asset and the canonical terminal branch copy.

## Safety

- Existing published tags are immutable and are never moved.
- `main` is not a merge target for UIUX-V42.

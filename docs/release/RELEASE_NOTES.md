# FOODEX 1.0.122 — Synchronized Release Notes

## Identity

- Dashboard / repository: `1.0.122`.
- Customer App: `1.0.122+122`.
- Driver App: `1.0.122+122`.
- Van App: `1.0.122+122`.

## Changes

- Forward-only release recovery from protected main `7d0caef1175ca778f98790abc8c56b0eca7a1600`, retaining validated Driver Wallet, premium responsive Home/Deliveries, encrypted Remember Me, biometric-only saved-session unlock, Customer/Van and Laravel Dashboard behavior.
- Preserve historical immutable `v1.0.120` at `fb6f1924b00e31e63c09009e6f537d55672baae9` and `v1.0.121` at `275c5c81379c7269b12f48374dd71e64b39044ce` without retargeting or replacing either Release's assets.
- Prevent Trial Distribution on release branches from publishing immutable canonical tags/releases or overwriting the canonical generated distribution branch. Trial builds remain downloadable as GitHub Actions artifacts.
- Synchronize all three Android applications and Dashboard identity to `1.0.122` with no business-logic, permission, finance or authentication changes.

## Release quality gate

Only the canonical Release Factory from the exact final **protected-main merge SHA** may publish `v1.0.122`. Require exact-head Repository Policy, Release Validation, Required CI, Arabic/English, all three APKs, Dashboard Update ZIP plus JSON/SHA256, clean Laravel Setup and fresh-install evidence, generated distribution and protected-main Dashboard update registry sync. Do not close #2466, #2464 or #2458 before final publication/provenance proof. The historical 1.0.120 and 1.0.121 tags/Releases/assets must remain untouched.

# FOODEX — permanent store-demo distribution branch

**Owner directive:** 2026-10-31. **Permanent ref:** `release/store-demo`. **Tracking issue:** [#2461](https://github.com/walidatiyaai2025-gif/FOOD/issues/2461). **Base:** `main` @ `c1db905c344d9217ba9b1c0911d8a19ef8f11453` (version `1.0.119`).

This is the owner-designated **store release source** for three Flutter apps, **Customer**, **Driver**, and **Van**, published under a **separate demo developer account**. It is *not* a demo backend, an automatically published release, or a fresh copy of production. Git branches share repository history; this branch is lightweight because it currently adds only this release contract and a status manifest.

## Never delete / never confuse with production

- **DO NOT DELETE**, rename, reset, or force-push `release/store-demo`. Never merge this branch **into** `main`, and never submit a PR **from** this branch into `main`. The branch is the permanent *target* for controlled store-demo updates, not a disposable PR head.
- Keep the dedicated tracking Issue #2461 OPEN. This protects the branch from the repository's scheduled cleanup because it contains the exact `BRANCH: release/store-demo` marker. Do **not** rely on this as a complete deletion safeguard: the immediate merged-PR cleanup has different behavior.
- **Repository admin follow-up:** create a GitHub **ruleset** targeting exactly `refs/heads/release/store-demo`, with **Restrict deletions** and **Block force pushes**; allow planned changes/PR merges under review. Also add this exact branch to both `keepNames` sets in the **default branch** copy of `.github/workflows/cleanup-merged-branch.yml`. Until those steps are verified, the branch exists but cannot be described as delete-proof.
- Protect store account, signing certificates, Android upload keys, Apple certificates, reviewer passwords and tokens outside Git. Never put them in this manifest.

## No changes to functionality or API

- Preserve existing API host **`https://foodex.50sols.com`** and public policy/support links. The production server remains the only backend specified by the owner; no alternative demo API is authorized.
- Production **tenant separation** is still required: create demo/reviewer users with constrained permissions and seeded non-sensitive records; prevent demonstration checkouts, dispatches, collections and notifications from touching real-world operations. Merely making a new app build with the same URL provides **no data isolation**.
- Preserve all three apps' business rules and UI, including B2B = **Van only**, B2C = **Driver only**, role permissions, GPS, biometric and push behavior. Only store-identity, signing, metadata and compliance changes are in scope for this branch.

## Store application identities — candidate values only

| App | Current production identifier | Proposed demo identifier **(unregistered)** |
| --- | --- | --- |
| Customer | `com.fiftysolution.foodex.customer` | `com.fiftysolution.foodex.customer.demo` |
| Driver | `com.fiftysolution.foodex.driver` | `com.fiftysolution.foodex.driver.demo` |
| Van | `com.foodex.van` | `com.foodex.van.demo` |

For a *separate listing* under another Google/Apple account, each uploaded app must have its own available, registered **applicationId / bundle ID**. These strings are **proposals only**, not proof they are available or configured. Add appropriate Android demo application identity support in the native configuration generator, matching `google-services.json` clients/Firebase, notifications and any deep link/biometric entitlements. Do not simply rename a manifest or overwrite production IDs. For Google Play, release **AAB** (not the project CI's ephemeral validation-key AAB) signed using the owner-controlled demo account upload key. For iOS, native Runner projects, team provisioning and archive validation must be completed first. Current repository contains only README under Customer/Driver `ios/` and no Van `ios/` directory.

## Repeatable promotion checklist

1. Read #2461, current `main` and store-demo branch HEAD, active worker/PR ownership and CI. Use the **existing branch**, never a replacement. If not currently building, report current status accurately.
2. Update store-demo from the vetted production code/release source by an additive, reviewed integration. Never force-push or rewrite this branch. Do not merge store-only configuration into production `main`.
3. Confirm the three Flutter sources and shared packages correspond to the intended reviewed source revision. Only store-specific configuration differs.
4. Verify demo IDs and correct Firebase clients; protect all credentials; check original API host, authentication, safe reviewer personas, legal/support URLs, permissions, location and notification behavior.
5. Build and test each platform with **real** store signing, store-compliant artifacts, separate monotonically increasing build codes, AR/EN, screenshots, privacy disclosures and app review credentials.
6. Document source SHA, three build identities, SHA-256/checksum artifacts, release notes, CI result, stores/tracks and status in `store/demo/manifest.json` and Issue #2461. Tag each shipped source using an **immutable** `store-demo/<version>-<build>` tag and retain artifacts in secure release storage.
7. Upload to the connected demo store account only after approved access/credentials and final owner authorization. CI validation artifacts signed with ephemeral keys are **not** upload-ready.
8. **Next request from owner:** “حط آخر نسخة على برانش الستور” = refresh this same branch after reviewing source and dependencies, then generate the three updated demo-store packages. No new long-lived branch.

### Existing build / metadata entry points

- `scripts/configure-mobile-native.py`: current IDs/branding/push native generator (must be demo-safe before publishing)
- `scripts/configure-android-store-signing.py`: Android signing validation
- `store/submission/metadata.json`: existing **production** store data, currently version 1.0.95 — *not* proof that demo branch is submission-ready
- `.github/workflows/store-submission-readiness.yml`: manual existing audit and **ephemeral key** AAB validation
- `.github/workflows/cleanup-merged-branch.yml`: ensure main's permanent allowlist gets explicit entry
- `docs/release/RELEASE_ARTIFACT_CONTRACT.md`: main distribution contract, independent of this long-lived demo lane

**Important:** A successful Git branch creation does *not* mean the three store apps or their iOS runners have been built, signed, tested or uploaded. Use `store/demo/manifest.json` for truthful readiness.

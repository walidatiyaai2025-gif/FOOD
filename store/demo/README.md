# FOODEX — permanent store-demo distribution branch

**Owner directive:** 2026-10-31. **Permanent ref:** `release/store-demo`. **Tracking issue:** [#2461](https://github.com/walidatiyaai2025-gif/FOOD/issues/2461). **Base:** `main` @ `c1db905c344d9217ba9b1c0911d8a19ef8f11453` (version `1.0.119`).

This is the owner-designated **store release source** for the **CURRENT** three Flutter apps, **Customer**, **Driver**, and **Van**. **Owner clarification (2026-10-31): these existing apps themselves are the demo versions and MUST retain their current exact names and application identities; no “Demo” suffix, no separate demo package/bundle IDs. Future production apps and their branches will be planned separately later; do not create them now.** They will be published under a **separate demo developer account**. It is *not* a demo backend, an automatically published release, or a fresh copy of production. Git branches share repository history; this branch is lightweight because it currently adds only this release contract and a status manifest.

## Never delete / never confuse with production

- **DO NOT DELETE**, rename, reset, or force-push `release/store-demo`. Current FOODEX apps are demo; this does not create or define any future production branch. Never merge this branch **into** `main`, and never submit a PR **from** this branch into `main`. The branch is the permanent *target* for controlled store-demo updates, not a disposable PR head.
- Keep the dedicated tracking Issue #2461 OPEN. This protects the branch from the repository's scheduled cleanup because it contains the exact `BRANCH: release/store-demo` marker. Do **not** rely on this as a complete deletion safeguard: the immediate merged-PR cleanup has different behavior.
- **Repository admin follow-up:** create a GitHub **ruleset** targeting exactly `refs/heads/release/store-demo`, with **Restrict deletions** and **Block force pushes**; allow planned changes/PR merges under review. Also add this exact branch to both `keepNames` sets in the **default branch** copy of `.github/workflows/cleanup-merged-branch.yml`. Until those steps are verified, the branch exists but cannot be described as delete-proof.
- Protect store account, signing certificates, Android upload keys, Apple certificates, reviewer passwords and tokens outside Git. Never put them in this manifest.

## No changes to functionality or API

- Preserve existing API host **`https://foodex.50sols.com`** and public policy/support links. The production server remains the only backend specified by the owner; no alternative demo API is authorized.
- Production **tenant separation** is still required: create demo/reviewer users with constrained permissions and seeded non-sensitive records; prevent demonstration checkouts, dispatches, collections and notifications from touching real-world operations. Merely making a new app build with the same URL provides **no data isolation**.
- Preserve all three apps' business rules and UI, including B2B = **Van only**, B2C = **Driver only**, role permissions, GPS, biometric and push behavior. Only store-identity, signing, metadata and compliance changes are in scope for this branch.

## Store application names and identities — preserve exactly

| App | Existing visible name (keep) | Existing Android/iOS application ID (keep) |
| --- | --- | --- |
| Customer | `FOODEX` | `com.fiftysolution.foodex.customer` |
| Driver | `FOODEX Driver` | `com.fiftysolution.foodex.driver` |
| Van | `FOODEX Van` | `com.foodex.van` |

**Do not add a “Demo” suffix or introduce `.demo` package/bundle IDs.** These are the CURRENT apps and are designated demo by the owner; a later production program will use separately planned branches (and identities only if separately decided). Before submitting to the separate demo developer account, check that each current application ID is available there or already owned by that account: package/bundle IDs cannot simply be registered again in a conflicting store listing. If there is a store ownership conflict, STOP and raise the conflict for an explicit owner decision (e.g. transfer versus separate ID); do not silently rename the apps or IDs. Preserve the existing `google-services.json` and Firebase configuration unless a store or runtime blocker requires reviewed change. For Google Play, release **AAB** (not the project CI's ephemeral validation-key AAB) signed using the owner-controlled demo account upload key. For iOS, native Runner projects, team provisioning and archive validation must be completed first. Current repository contains only README under Customer/Driver `ios/` and no Van `ios/` directory.

## Repeatable promotion checklist

1. Read #2461, current `main` and store-demo branch HEAD, active worker/PR ownership and CI. Use the **existing branch**, never a replacement. If not currently building, report current status accurately.
2. Update store-demo from the vetted production code/release source by an additive, reviewed integration. Never force-push or rewrite this branch. Do not merge store-only configuration into production `main`.
3. Confirm the three Flutter sources and shared packages correspond to the intended reviewed source revision. Only store-specific configuration differs.
4. Verify the EXISTING app names/IDs can be submitted under the demo developer account and match their existing Firebase clients; protect all credentials; check original API host, authentication, safe reviewer personas, legal/support URLs, permissions, location and notification behavior.
5. Build and test each platform with **real** store signing, store-compliant artifacts, separate monotonically increasing build codes, AR/EN, screenshots, privacy disclosures and app review credentials.
6. Document source SHA, three build identities, SHA-256/checksum artifacts, release notes, CI result, stores/tracks and status in `store/demo/manifest.json` and Issue #2461. Tag each shipped source using an **immutable** `store-demo/<version>-<build>` tag and retain artifacts in secure release storage.
7. Upload to the connected demo store account only after approved access/credentials and final owner authorization. CI validation artifacts signed with ephemeral keys are **not** upload-ready.
8. **Next request from owner:** “حط آخر نسخة على برانش الستور” = refresh this same branch after reviewing source and dependencies, then generate the three updated demo-store packages. No new long-lived branch.

### Existing build / metadata entry points

- `scripts/configure-mobile-native.py`: current IDs/branding/push native generator (must be demo-safe before publishing)
- `scripts/configure-android-store-signing.py`: Android signing validation
- `store/submission/metadata.json`: existing store metadata listing the current FOODEX names/identifiers (currently version 1.0.95); older text may call them 'production' but the owner now designates these **current apps as demo**. Metadata alone is *not* proof of store submission readiness
- `.github/workflows/store-submission-readiness.yml`: manual existing audit and **ephemeral key** AAB validation
- `.github/workflows/cleanup-merged-branch.yml`: ensure main's permanent allowlist gets explicit entry
- `docs/release/RELEASE_ARTIFACT_CONTRACT.md`: main distribution contract, independent of this long-lived demo lane

**Important:** A successful Git branch creation does *not* mean the three store apps or their iOS runners have been built, signed, tested or uploaded. Use `store/demo/manifest.json` for truthful readiness.

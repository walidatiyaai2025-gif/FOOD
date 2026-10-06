# FOODEX Release Artifact Contract

This is a mandatory repository-level release contract. It applies to every FOODEX version promotion and to every worker or workflow asked to "create/publish a new release".

A release is **not complete** until the synchronized Dashboard update and all three Android application APKs have been produced from the same validated release version/source commit.

## Required Release layout

Every completed release must leave the latest downloadable artifacts under `Release/`:

- `FOODEX-Customer-<VERSION>.apk`
- `FOODEX-Driver-<VERSION>.apk`
- `FOODEX-Van-<VERSION>.apk`
- `FOODEX-Customer.apk` — compatibility/latest alias of the same Customer build.
- `FOODEX-Driver.apk` — compatibility/latest alias of the same Driver build.
- `FOODEX-Van.apk` — compatibility/latest alias of the same Van build.
- `LATEST_RELEASE.json` — machine-readable index containing version, build number, source commit, exact versioned filenames, sizes and SHA-256 values.
- `BUILD_INFO.json`
- `FOODEX-Laravel-Setup.zip` when the release produces/refreshes the install package.

The Dashboard update remains under `Release/Updates/`:

- `FOODEX-Update.zip`
- `FOODEX-Update.json`
- `FOODEX-Update.sha256.txt`
- `FOODEX-Update.files.txt`

If the Dashboard update manifest requires a full redeploy, the release must still provide the manifest and the synchronized Setup ZIP according to the existing update safety policy.

## Automatic release behavior

When `VERSION` is promoted, or when the canonical distribution workflow is manually dispatched for a release, the workflow must automatically:

1. read and validate the FOODEX version;
2. require Customer, Driver and Van mobile versions/build numbers to match;
3. build all three Android APKs;
4. validate each APK's application identity and version;
5. create the versioned APK filenames under `Release/`;
6. retain the non-versioned latest aliases for compatibility;
7. build/refresh the Dashboard Update Center package under `Release/Updates/`;
8. write `BUILD_INFO.json` and `LATEST_RELEASE.json`;
9. publish the versioned APKs plus Dashboard update assets to the matching GitHub Release;
10. refresh the generated distribution branch/artifact so the `Release/` folder always represents the latest release.

No manual follow-up request for "give me the latest APK" is part of the normal release process.

## Three-app parity gate

Customer, Driver and Van are first-class release artifacts. A release must fail rather than silently complete with only one or two APKs.

If a fourth first-class mobile application is added in the future, this contract and the centralized release workflow must be updated before that application is considered release-ready.

## Naming rule

Human-downloadable APK filenames must contain both the application identity and FOODEX release version. Do not publish anonymous names such as `app-release.apk` as the canonical downloadable asset.

Canonical examples:

- `FOODEX-Customer-1.0.59.apk`
- `FOODEX-Driver-1.0.59.apk`
- `FOODEX-Van-1.0.59.apk`

## Source integrity

All release assets for one version must come from the same intended release source commit and version identity. `LATEST_RELEASE.json` and `BUILD_INFO.json` are part of the evidence.

Do not reuse an APK from an older version while publishing a newer Dashboard update.

Existing immutable version tags/releases must not be moved to a different source commit merely to rerun packaging.

## Definition of Done

A version promotion is incomplete if any of the following is true:

- one of Customer / Driver / Van APKs is missing;
- an APK has the wrong version/build identity;
- versioned APK filenames are missing from `Release/`;
- `LATEST_RELEASE.json` is absent or does not list all three apps;
- the Dashboard update assets were not refreshed for the release;
- the generated distribution does not contain the synchronized latest artifacts;
- published release assets and repository release manifests disagree on version, source commit or checksum.

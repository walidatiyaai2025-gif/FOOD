# FOODEX Release Artifact Contract

This is a mandatory repository-level release contract. It applies to every FOODEX version promotion and to every worker or workflow asked to "create/publish a new release".

A release is **not complete** until the synchronized Dashboard update and all three Android application APKs have been produced from the same validated release version/source commit.

For releases that support a fresh Laravel install, the release is also not complete until the canonical Setup ZIP has been rebuilt from the exact final implementation source commit and has passed a clean install using the ZIP only.

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

### Fresh Setup source integrity

For a release with `Release/FOODEX-Laravel-Setup.zip`:

- record the exact final implementation source SHA before generated artifact publication;
- `Release/BUILD_INFO.json.source_commit` must equal that implementation source SHA;
- `Release/FRESH_INSTALL_EVIDENCE.json.source_commit` must equal that implementation source SHA when fresh-install evidence is part of the release;
- the Setup ZIP must be built from that exact source and its size/SHA-256 must match BUILD_INFO;
- the fresh-install workflow must validate a clean install from the ZIP only, not from the repository working tree;
- after that source SHA, a generated-artifact publication commit is allowed only to commit release artifacts/evidence back to the canonical release branch;
- that publication commit must not add or change business code delivered by the Setup;
- if implementation code changes after the recorded source SHA, the Setup is stale and must be rebuilt and revalidated.

For the active FOODEX 1.0.58 Van mission, the owner-facing final Setup source is `release/1.0.58-van-complete`.

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
- published release assets and repository release manifests disagree on version, source commit or checksum;
- a fresh-install release contains code newer than the source SHA recorded in BUILD_INFO/FRESH_INSTALL_EVIDENCE;
- `FOODEX-Laravel-Setup.zip` was not rebuilt from the exact final implementation source SHA;
- clean installation from the Setup ZIP only did not pass;
- the canonical release branch contains a stale Setup ZIP from an older implementation head.

### Terminal owner-downloadable release branch

When an active Mission defines a dedicated terminal release branch, Mission completion requires the final build to be persisted there in addition to normal GitHub Release/generated-distribution publication.

For UIUX-V42 this branch is:

`release/1021-uiux-v42-final-real-build`

After its release workflow passes:

- the branch must contain the actual final `Release/FOODEX-Laravel-Setup.zip`;
- the branch may contain a final generated-artifact publication commit after the source/version-promotion commit;
- that generated commit may modify only release artifacts/evidence, not business/application source;
- `BUILD_INFO.json` / `LATEST_RELEASE.json` retain the exact source commit that produced the build;
- the Setup ZIP SHA-256 on the final branch must match the immutable GitHub Release asset and the generated distribution branch copy;
- a user must be able to obtain the real installable Setup directly from that final branch without rebuilding source.

A Mission must remain open if its terminal branch points at newer code than the available Setup build, or if the branch contains only source code without the final owner-downloadable Setup artifact.


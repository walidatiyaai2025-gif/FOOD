# Mobile release-mode validation artifacts

RC-05A (#133) upgrades both reusable Flutter CI workflows to release-mode builds.
The existing analysis, tests and stable job names remain. Both platforms/apps are
required through `required-ci-gate` when their code, packaging script or its tests change.

Each successful run uploads these GitHub Actions artifacts for 14 days:

| Application | Android | iOS |
|---|---|---|
| Customer | `foodex-customer-android-release-validation` | `foodex-customer-ios-release-validation` |
| Driver | `foodex-driver-android-release-validation` | `foodex-driver-ios-release-validation` |

Every artifact contains the build, `manifest.json`, `SHA256SUMS` and a purpose notice.
The manifest records exact checked-out source SHA (the PR merge commit in PR CI),
mobile/platform versions, CI build number, size, SHA-256, endpoint and signing status.
Download from the corresponding completed Actions run and verify `sha256sum -c SHA256SUMS`.

## Limits

These are **validation-only artifacts**, not production distribution packages:

- Builds deliberately target `https://foodex-validation.invalid`; they cannot connect to a production backend.
- Android release mode uses the generated Flutter template debug key. It is not production-signed.
- iOS uses `--release --no-codesign`; the archived `.app` is not a signed/installable IPA.
- Generated native identifiers/icons are not approved production identity. The existing FOODEX Flutter theme remains unchanged.
- #125 still requires approved production HTTPS API URL, separate Customer/Driver application/bundle IDs, native branding, signing and final installation/device acceptance.
- No app-store upload, deployment or release publication occurs.

## Reproduction

Run the commands in the appropriate reusable workflow, including Flutter scaffolding,
dependency resolution, analysis/tests and release build. Use Python 3.9+ to run
`scripts/package-mobile-artifact.py`; its CLI requires app, platform, artifact input,
output directory, exact source commit and build number. Packaging cannot certify signing
or runtime success; only use it after the documented CI validation build.

Packaging tests: `python3 -m unittest discover -s scripts/tests -p 'test_mobile_artifact.py'`.

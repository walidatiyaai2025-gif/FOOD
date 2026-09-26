# iOS release native-brand acceptance

Issue #161 closes the native display-name gap discovered under release parent #125.

Both mobile applications still generate their iOS scaffolding during CI. The reusable
release-validation workflows now set the approved native `CFBundleDisplayName` after
scaffolding and verify the value again from the **built Runner.app/Info.plist** after
the release `--no-codesign` build.

| App | Required iOS display name |
|---|---|
| Customer | FOODEX |
| Driver | FOODEX Driver |

The verification intentionally does not set or certify production bundle identifiers,
signing identities, provisioning profiles, production API endpoints or icon artwork.
Those values remain explicit #125 production-readiness inputs and must not be guessed.

The resulting iOS artifacts remain unsigned validation artifacts. A mismatch between
the approved display name and the built application fails the affected reusable mobile
CI job before artifact packaging/upload.

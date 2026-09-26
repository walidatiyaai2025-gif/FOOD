# Android release manifest acceptance

Issue #138 addresses a defect found in the actual Driver release APK from validation
run 36208520786: the compiled manifest lacked `android.permission.INTERNET`, and
its native application label was the generated project name.

Both applications now commit a release-source-set manifest overlay. Flutter still
generates the main Android scaffolding/activity configuration; the overlay adds
network permission and the native display name without replacing that scaffold.

| App | Native release label | Permission |
|---|---|---|
| Customer | FOODEX | android.permission.INTERNET |
| Driver | FOODEX Driver | android.permission.INTERNET |

The Android CI job inspects the **compiled APK**, using the installed SDK `aapt`,
before packaging/upload. It must find the exact INTERNET permission declaration
and expected native label. A missing permission/label fails the existing required
mobile validation job. The test runs after `flutter create` and the release build,
so it also catches a future scaffold-generation change that removes the overlay.

This grants networking capability, not endpoint connectivity or a signed production
release. Validation builds still use the explicitly non-resolving endpoint and
template debug signing described in MOBILE_RELEASE_VALIDATION.md. Production
identifiers, icons, signing, endpoint and real-device acceptance remain in #125.

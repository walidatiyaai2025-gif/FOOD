# FOODEX Driver App Bootstrap

Issue #5 owns the Flutter Driver App platform baseline only. Delivery workflows remain separate issue-owned work.

## Platform baseline

- One Flutter codebase targets Android and iOS.
- The shared app shell is reserved for both B2C_DRIVER and B2B_DRIVER roles; channel separation remains backend-authoritative and is not implemented as product behavior in this bootstrap issue.
- CI materializes native Android/iOS scaffolding with `flutter create` and validates both targets.
- Android validation builds a debug APK.
- iOS validation performs a debug `--no-codesign` build.
- The root app is Arabic-first with explicit RTL direction.
- Arabic and English are the supported bootstrap locales.

## Validation

From `apps/driver_app/`:

```bash
flutter pub get
flutter analyze
flutter test
flutter build apk --debug
flutter build ios --debug --no-codesign
```

The repository `required-ci-gate` runs the applicable Driver Flutter and Driver iOS validations whenever `apps/driver_app/**` changes.

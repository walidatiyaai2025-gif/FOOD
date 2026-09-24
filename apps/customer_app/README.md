# FOODEX Customer App Bootstrap

Issue #4 owns the Flutter Customer App platform baseline only. Product journeys remain separate issue-owned work.

## Platform baseline

- One Flutter codebase targets Android and iOS.
- CI materializes native Android/iOS scaffolding with `flutter create` and validates both targets.
- Android validation builds a debug APK.
- iOS validation performs a debug `--no-codesign` build.
- The root app is Arabic-first with explicit RTL direction.
- Arabic and English are the supported bootstrap locales.

## Validation

From `apps/customer_app/`:

```bash
flutter pub get
flutter analyze
flutter test
flutter build apk --debug
flutter build ios --debug --no-codesign
```

The repository `required-ci-gate` runs the applicable Customer Flutter and Customer iOS validations whenever `apps/customer_app/**` changes.

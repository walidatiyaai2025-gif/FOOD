# Initial source import

- Source archive: `foodex_marketplace_mockup_source.zip` supplied by the owner.
- Archive SHA-256: `4f349e7ed0363ebe4d2be26a931ee2990ac78b3674985c88fa1f7aa3d6be4c0c`.
- Destination: `apps/marketplace` (archive wrapper directory removed).
- The 14 original Dart/configuration/build/integration files are unchanged.
- Import additions: repository README context, generated-file ignores, an empty
  asset-directory placeholder, this note and a resolved `pubspec.lock`.

## Validation at import

Validated with Flutter 3.44.8 / Dart 3.12.2:

- `flutter pub get`: passed.
- `flutter analyze --no-pub --no-fatal-infos`: exit 0; no errors or warnings,
  nine informational diagnostics from the supplied source (three `const`
  suggestions and six deprecated Radio API usages).
- Source/configuration/build/integration files compared to the extracted archive
  using SHA-256: all 14 matched.
- `git diff --check`: passed.

No APK, native platform scaffold or runtime acceptance evidence is included.
The supplied build helpers are retained as received and have not been executed.
The UI remains a mock-data prototype; see `README.md` and `INTEGRATION_MAP.md`
for the next integration phase.

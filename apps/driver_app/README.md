# FOODEX Driver App

The Driver App uses one Flutter codebase for Android and iOS and one navigation shell for both driver channels.

## Navigation architecture

- `DriverChannel.b2c` is restricted to `/driver/b2c/**`.
- `DriverChannel.b2b` is restricted to `/driver/b2b/**`.
- Cross-channel navigation is rejected by the router before a product screen is rendered.
- Unknown routes inside the active channel resolve to a deterministic not-found screen.
- The shell is Arabic-first with explicit RTL direction and supports Arabic and English.
- Backend authorization remains authoritative; client route separation is an additional UX boundary, not a security substitute.

Current shell routes:

- `/driver/b2c/home`
- `/driver/b2c/deliveries`
- `/driver/b2b/home`
- `/driver/b2b/deliveries`

Delivery workflows, assignment state and product behavior remain separate issue-owned work.

## Validation

From `apps/driver_app/`:

```bash
flutter pub get
flutter analyze
flutter test
flutter build apk --debug
flutter build ios --debug --no-codesign
```

The repository `required-ci-gate` runs Driver Flutter and iOS validation whenever `apps/driver_app/**` changes.

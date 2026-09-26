# FOODEX Driver App

The Driver App uses one Flutter codebase for Android and iOS. The authenticated backend identity determines whether the runtime is B2C_DRIVER or B2B_DRIVER; callers cannot select a production channel locally.

## Runtime journey

1. The app requires `FOODEX_API_BASE_URL` (or an injected repository in tests).
2. Login uses `POST /api/v1/auth/login`.
3. Exactly one backend driver role (`B2C_DRIVER` or `B2B_DRIVER`) selects the route partition.
4. Assignments load from `GET /api/v1/driver/assignments`.
5. The backend returns `available_statuses`; Flutter renders only those server-authorized actions.
6. Status changes use `POST /api/v1/driver/assignments/{assignment}/status` and reload persisted state.
7. A 401 expires the local session; 403 never changes the local channel boundary.

## Navigation architecture

- `DriverChannel.b2c` is restricted to `/driver/b2c/**`.
- `DriverChannel.b2b` is restricted to `/driver/b2b/**`.
- Cross-channel navigation is rejected before a product screen is rendered.
- Backend authorization remains authoritative; client route separation is an additional UX boundary, not a security substitute.
- Loading, empty, error, offline and expired-session outcomes are explicit.
- Arabic RTL and English LTR use the shared FOODEX theme and translation catalog.

## Release configuration

A production build must pass the API origin, for example:

```bash
flutter build apk --release --dart-define=FOODEX_API_BASE_URL=https://foodex.example/
```

Issue #125 owns the final production URL, application IDs, signing and published release artifacts. Credentials must never be committed.

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

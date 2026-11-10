# FOODEX Driver App

The Driver App uses one Flutter codebase for Android and iOS. Production Driver runtime is **Retail (B2C) only**. Wholesale (B2B) fulfillment, including manual dispatch, belongs exclusively to Van App. Historical B2B Driver assignments remain audit/finance records and grant no live execution access.

## Runtime journey

1. The app requires `FOODEX_API_BASE_URL` (or an injected repository in tests).
2. Login uses `POST /api/v1/auth/login`.
3. Driver login requires an active Retail/B2C Driver profile, `B2C_DRIVER` role, `deliveries.b2c.execute` and the matching Retail store. Legacy B2B login and tokens are denied server-side.
4. Assignments load from `GET /api/v1/driver/assignments`.
5. The backend returns `available_statuses`; Flutter renders only those server-authorized actions.
6. Status changes use `POST /api/v1/driver/assignments/{assignment}/status` and reload persisted state.
7. A 401 expires the local session; 403 never changes the local channel boundary.

## Navigation architecture

- Assignment APIs are shared and enforce the authenticated Driver channel/store.
- Legacy B2B Driver identities and secure saved sessions are denied and cleared from Driver App; the UI explains how to use Van App instead.
- B2B automatic/manual fulfillment is Van-only; all Driver execution, push and assignment APIs are B2C-only.
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

# FOODEX Customer App

The Customer App is one Flutter codebase for Android and iOS with Arabic-first RTL behavior and English support.

## Navigation architecture

Issue #14 establishes one central navigation registry under `lib/core/routing/`.

- B2C guest routes include entry, store selection, home, offers, catalog, product details and cart.
- B2C account/checkout routes are protected by the authentication boundary.
- B2B routes are a separate logical partition under `/b2b/**`; protected B2B routes require an authenticated B2B customer session.
- A B2C authenticated session cannot enter protected B2B routes and vice versa.
- Route definitions support dynamic reference paths such as `/products/:id`, `/orders/:id/track` and `/b2b/orders/:id`.
- The current route widgets are intentionally shell placeholders. Product UI remains owned by later feature issues.
- Backend authorization remains authoritative; Flutter guards are navigation boundaries, not security enforcement.

## Platform validation

From `apps/customer_app/`:

```bash
flutter pub get
flutter analyze
flutter test
flutter build apk --debug
flutter build ios --debug --no-codesign
```

The repository `required-ci-gate` runs the applicable Customer Flutter and Customer iOS validations whenever `apps/customer_app/**` changes.

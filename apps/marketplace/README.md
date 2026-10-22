# Foodx Marketplace Mockup

Standalone Flutter mockup for the Foodx restaurant marketplace, imported from
`foodex_marketplace_mockup_source.zip` into `apps/marketplace`.

This is the initial source foundation for later FOODEX integration. All restaurant,
menu, cart, checkout, order and account behavior is mocked. There is no production
API connection, real authentication, payment processing or persistent order storage.
The existing Customer, Driver and Van applications remain separate.

## Project structure

- `lib/main.dart`: standalone application entry point and mock routes.
- `lib/screens/`: marketplace, commerce and account screens.
- `lib/widgets/`: mockup UI components.
- `lib/core/`: theme and in-memory app state.
- `lib/models/` and `lib/data/`: prototype models and sample data.
- `assets/`: reserved for future local assets; current sample images use remote URLs.
- `INTEGRATION_MAP.md`: proposed mapping to FOODEX domains for the next phase.

The current UI uses Arabic/RTL. Production localization, responsive/runtime QA,
backend contracts and integration acceptance remain future work.

## Included flows
- Marketplace home with the current Foodx-style compact shared header
- Restaurant discovery and filters
- Restaurant details and menu
- Meal customization/modifiers
- Cart
- Checkout
- Orders history
- Order tracking
- Offers
- Notifications
- Favorites
- Profile / settings mock
- Guest/logged-in header behavior toggle in the profile screen

## Run
The archive contains Dart source and configuration; native platform folders are
not included yet. Start in this directory with a Flutter SDK compatible with the
declared Dart constraint in `pubspec.yaml`:

```bash
cd apps/marketplace
```

The supplied setup/build helpers generate the Android shell and restore the mockup
source after `flutter create`:

```powershell
# Windows
powershell -ExecutionPolicy Bypass -File .\build_apk.ps1
```

```bash
# macOS / Linux (also requires python3)
bash ./build_apk.sh
```

After the platform shell exists, run on a connected Android device/emulator:

```bash
flutter run
```

Build APK:

```bash
flutter build apk --release
```

The build helpers are supplied prototype tooling, not a production release pipeline.
Images and Google Fonts may require internet access. Do not publish the mockup as
a FOODEX release or treat sample prices, offers, orders or login state as live data.

## Next integration phase

Use `INTEGRATION_MAP.md` as a planning reference and validate each target against
the current repository contracts. Replace mock state/data with the canonical
FOODEX services, reuse authentication and ordering ownership, add persistence and
error states, then complete AR/EN and runtime acceptance before activation.

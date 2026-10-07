# FOODEX Driver UIUX Mockup Lab

This is a **design-only mirror of the current Driver App**, not a new Driver product concept.

Production source reviewed from:
- `feat/1039-driver-v42-full-sweep`
- Driver source HEAD reviewed: `2a02d51f7a7e89403e0efea5e80cc78415747bf5`
- `apps/driver_app/lib/navigation.dart`
- `apps/driver_app/lib/core/navigation/driver_shell.dart`
- `apps/driver_app/lib/features/auth/driver_login.dart`
- `apps/driver_app/lib/features/delivery/active/driver_active_journey.dart`
- `apps/driver_app/lib/features/delivery/completion/driver_completion_sheet.dart`
- `apps/driver_app/lib/features/notifications/driver_notification_page.dart`
- `apps/driver_app/lib/features/wallet/driver_wallet_page.dart`
- `apps/driver_app/lib/core/theme/foodex_theme.dart`

## Mirror rule

The mockup follows the current Driver App information architecture and operational flow:
- Driver login with visible Driver App identity
- Remember Me + biometric concept
- precise-location gate
- Home
- Deliveries
- Assignment detail
- start delivery
- collect & deliver
- delivered / failed completion decision with proof attachment
- invoice presentation
- notifications and exact-order open behavior
- wallet / custody / remittance

The current persistent Driver navigation remains:
- Home
- Deliveries
- Notifications

Wallet remains reachable from Home, matching the current app.

## FOODEX identity

The mockup uses the real Driver App FOODEX palette:
- Green `#158A3A`
- Green Dark `#165D2D`
- Green Bright `#27B658`
- Green Soft `#EAF7EF`
- Orange `#EE731C`
- Blue `#4B8CF5`
- Red `#EF5350`
- Ink `#172033`
- Background `#F6F8F6`

It also copies the real FOODEX Driver branding asset into the isolated build and uses Alexandria typography via the same `google_fonts` family used in production.

## Safety

- fake display data only
- no backend/API
- no real login
- no real location tracking
- no real navigation launch
- no collection/remittance mutation
- no push registration
- no database or production side effects

Artifact:
`FOODEX-Driver-UIUX-Mockup-APK`

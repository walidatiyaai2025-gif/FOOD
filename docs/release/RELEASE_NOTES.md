# FOODEX 1.0.58 Van Full-Install Release Notes

Status: Van national field-operations release candidate for a clean isolated environment.

## Release identity

- Dashboard / backend: `1.0.58`
- Customer app: `1.0.58+58`
- Driver app: `1.0.58+58`
- Van app: `1.0.58+58`
- Environment origin: `https://vanfoodex.50sols.com`
- Android Van application ID: `com.foodex.van`

## Environment

This release is prepared as a full first-install deployment for the isolated Van environment.

- Web/API origin: `https://vanfoodex.50sols.com`
- Installer: `https://vanfoodex.50sols.com/install`
- Default database: `solscool_vanfoodex`
- Default database username: `solscool_vanfoodex`
- Web document root: `backend/public`

Secrets are intentionally not committed. Database passwords, production signing keys and Firebase service-account credentials must be entered only in the deployment environment / Dashboard settings.

## Van scope

The release consolidates the #936 Van field-operations train, including territory/routing foundations, Van registry and assignments, shared collections/custody/remittance, fleet location, customer visits/orders, Van control-plane parity, finance reconciliation surfaces, Firebase/FCM device registration and Dashboard notification targeting.

## Firebase / notifications

- Van Android package: `com.foodex.van`
- Van Firebase client configuration is bundled for Android.
- Van devices register through the canonical `/api/v1/push/devices` endpoint as `app=van`.
- Dashboard Mobile & Push Settings supports Van providers and test sends.
- Notification and campaign targeting supports Van.
- Server-side Firebase sending still requires the Firebase/Google Service Account JSON to be entered in Dashboard Mobile & Push Settings; that credential is not stored in Git.

## Distribution artifacts

The release workflow produces:

- `FOODEX-Laravel-Setup.zip` — complete first-install backend package.
- `FOODEX-Van.apk` — Van Android tester/acceptance APK connected to `https://vanfoodex.50sols.com`.
- `FOODEX-Customer.apk` and `FOODEX-Driver.apk` — synchronized environment APKs.
- `BUILD_INFO.json` — checksums, package IDs, version/build identity and API origin.
- Dashboard Update Center assets for 1.0.58.

Android APKs produced by this workflow remain test-signed until the production keystore is supplied.

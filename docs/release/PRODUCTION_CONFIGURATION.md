# FOODEX Production Configuration — First Install

Public deployment identity approved for the first FOODEX installation:

- Web/API origin: `https://foodex.50sols.com`
- MySQL/MariaDB database: `solscool_foodex`
- MySQL/MariaDB username: `solscool_foodex`
- Customer mobile API default: `https://foodex.50sols.com`
- Driver mobile API default: `https://foodex.50sols.com`

Use `backend/.env.production.example` as the non-secret first-install template.

## Secrets

Do not commit database passwords, Firebase service-account private keys, APNs keys,
Android keystores, Apple certificates/provisioning profiles, or production tokens.
Supply those through the deployment environment/secret store.

## Mobile override

Both Flutter binaries use the production origin by default. Non-production builds can override it:

`--dart-define=FOODEX_API_BASE_URL=https://staging.example.com`

## Firebase naming

Use one Firebase project for the production environment:

- Project display name: **FOODEX Production**
- Firebase project ID supplied for production: `comfiftysolutionfoodexcustomer`
- Android/iOS app nickname: **FOODEX Customer** for the Customer binary
- Android/iOS app nickname: **FOODEX Driver** for the Driver binary

Final native identities for the first production release:

- Customer Android applicationId / iOS bundle ID: `com.fiftysolution.foodex.customer`
- Driver Android applicationId / iOS bundle ID: `com.fiftysolution.foodex.driver`

These identifiers are enforced in Customer/Driver CI after Flutter scaffolding and are
the package/bundle IDs to register in Firebase.

Android production Firebase client configuration is now versioned at:

- `apps/customer_app/android/app/google-services.json`
- `apps/driver_app/android/app/google-services.json`

The native-generation script validates that each file contains its approved package ID
and applies the Google Services Gradle plugin after `flutter create`. Android therefore
uses the native Firebase client configuration by default. The four `FOODEX_FIREBASE_*`
`dart-define` values remain supported as explicit environment overrides.

The supplied files configure project `comfiftysolutionfoodexcustomer` and sender/project
number `1090949588488`; the Driver file includes the approved Driver package client.

iOS production Firebase registration is still external until the matching Apple Firebase
client configuration is supplied. Do not commit service-account private keys, OAuth access
tokens, APNs keys, signing keystores, Apple certificates, or provisioning profiles.

## Dashboard live notifications

Real-time Management Dashboard notifications are tracked by #211 and are part of the
usable-product completion scope.

## Dashboard shared preview runtimes

The supported production distribution target is same-origin static hosting:

- Customer runtime: `https://foodex.50sols.com/preview/customer/`
- Driver runtime: `https://foodex.50sols.com/preview/driver/`
- Runtime/allowed origin: `https://foodex.50sols.com`
- Contract: `shared-flutter-v1`

The release package is built by `.github/workflows/preview-runtime-distribution.yml`.
It compiles the real Customer and Driver `preview_main.dart` entrypoints with explicit
base hrefs, packages immutable version/commit-identified archives, and produces the exact
Dashboard environment snippet. See `docs/release/PREVIEW_RUNTIME_DISTRIBUTION.md`.

These documented URLs are configuration targets, not deployment evidence. After the
generated static assets are deployed, the workflow must be manually re-run with
`verify_deployed=true` (or equivalent production evidence recorded) before the runtime
URLs are considered live.


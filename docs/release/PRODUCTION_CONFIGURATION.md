# FOODEX Production Configuration — First Install

Public deployment identity approved for the first FOODEX installation:

- Web/API origin: `https://foodex.50sols.com`
- PostgreSQL database: `solscool_foodex`
- PostgreSQL username: `solscool_foodex`
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
- Suggested project ID: `foodex-50sols-prod` (only if available in Firebase)
- Android/iOS app nickname: **FOODEX Customer** for the Customer binary
- Android/iOS app nickname: **FOODEX Driver** for the Driver binary

Final native identities for the first production release:

- Customer Android applicationId / iOS bundle ID: `com.fiftysolution.foodex.customer`
- Driver Android applicationId / iOS bundle ID: `com.fiftysolution.foodex.driver`

These identifiers are enforced in Customer/Driver CI after Flutter scaffolding and are
the package/bundle IDs to register in Firebase.

Firebase client values are public application configuration but remain external so one
repository can target controlled environments. Supply each mobile build with:

- `FOODEX_FIREBASE_API_KEY`
- `FOODEX_FIREBASE_APP_ID`
- `FOODEX_FIREBASE_MESSAGING_SENDER_ID`
- `FOODEX_FIREBASE_PROJECT_ID`

Do not commit service-account private keys, OAuth access tokens, APNs keys, signing
keystores, Apple certificates, or provisioning profiles. The mobile client disables
Firebase gracefully when the four Firebase build values are absent.

## Dashboard live notifications

Real-time Management Dashboard notifications are tracked by #211 and are part of the
usable-product completion scope.

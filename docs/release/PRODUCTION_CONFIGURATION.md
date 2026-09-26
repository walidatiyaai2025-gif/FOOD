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

The Firebase Android package names and iOS bundle IDs must exactly match the final
approved native application IDs. They are intentionally not invented in this repository.
Track the client integration in #210.

## Dashboard live notifications

Real-time Management Dashboard notifications are tracked by #211 and are part of the
usable-product completion scope.

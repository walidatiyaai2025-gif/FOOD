# FOODEX 1.0.44 Release Notes

Status: synchronized Customer + Driver Journey V2 production distribution.

## Release identity

- Dashboard: `1.0.44`
- Customer app: `1.0.44+44`
- Driver app: `1.0.44+44`
- Customer runtime/footer identity: `1.0.44`
- Driver runtime/footer identity: `1.0.44`
- Driver diagnostics current identity: `1.0.44`
- Driver diagnostics build identity: `44`

## Deployable delta since distributed 1.0.43

### Customer Journey V2
- Converge production B2C navigation on the NEW Retail journey only.
- Preserve authoritative Retail store/channel context across guest browsing, authentication/registration return, checkout, deep links, notifications and orders.
- Ship NEW account/profile, addresses, favorites, notification center and authoritative order/tracking surfaces.
- Verify the mandatory guest Retail journey end to end, including same-store cart merge and operational order/registration notifications.
- Remove the obsolete legacy Customer runtime and keep an anti-regression guard against reintroduction.

### Driver Journey V2
- Converge ordinary launch, delivery navigation, push/deep links and notification-center assignment opens on the NEW Driver runtime.
- Ship authoritative active-assignment actions and shared start/delivered/failed completion flow with notes and required delivery proof.
- Keep Dashboard delivery evidence and lifecycle notifications synchronized with the authoritative assignment state.
- Verify assignment-to-proof E2E acceptance.
- Remove the obsolete legacy Driver runtime and keep an anti-regression guard against reintroduction.

## Dashboard update bundle

- Target version: `1.0.44`
- Minimum current version: `1.0.6`
- Contains migrations: `true`
- Requires full redeploy: `false`
- SHA-256: `586210c0d29c28eb9313449e340cf244fb05abc81b9ab42c7dd62d570da9c10d`
- Package files: `679`
- Package size: `73,056,557` bytes

## Explicit non-activation statement

This release does **not**:
- change production minimum-supported AppVersion rows;
- enable force-update;
- enable Driver fresh-location enforcement;
- enable the Assistant in production by default;
- change the Assistant from read-only by default.

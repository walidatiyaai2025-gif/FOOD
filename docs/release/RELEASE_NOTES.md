# FOODEX 1.0.46 Release Notes

Status: synchronized unified Wholesale/Retail commerce-isolation production distribution.

## Release identity

- Dashboard: `1.0.46`
- Customer app: `1.0.46+46`
- Driver app: `1.0.46+46`
- Customer runtime/footer identity: `1.0.46`
- Driver runtime/footer identity: `1.0.46`
- Driver diagnostics current identity: `1.0.46`
- Driver diagnostics build identity: `46`
- Reserved version `1.0.45`: intentionally skipped and not published.

## Deployable delta since distributed 1.0.44

### Unified commerce identity and ownership
- Make platform identity, Retail Merchant ownership/management and Store-scoped B2C relationships authoritative.
- Block owned/managed Retail Merchants from purchasing from their own Retail Store at backend authorization boundaries.
- Keep the Customer App platform-first, with Retail Store entry through exact Retail Merchant placement/banner context.

### B2B/B2C operational isolation
- Route Wholesale and Retail orders to the correct seller/store/channel operational context.
- Isolate B2B and B2C Address Books and checkout address selection.
- Add My Wholesale Orders with backend-authoritative order and delivery timeline data.
- Prevent cross-store and cross-channel operational visibility.

### Driver and notification convergence
- Enforce Wholesale/Retail Driver tenant and assignment isolation.
- Converge all Driver production routes on the shared shell and keep assignment status actions inside Assignment Details.
- Isolate operational notification audiences, dedupe/deep-link context and authorization by customer/driver/store/channel.

### App Preview and final acceptance
- Auto-launch published Customer Preview in guest/visitor context.
- Auto-select the first eligible authorized Driver for Driver Preview, with a clear create/activate Driver state when none exists.
- Include final Customer + Driver E2E convergence and the 1.0.45 skip/release-identity guard.

## Dashboard update bundle

- Target version: `1.0.46`
- Minimum current version: `1.0.6`
- Contains migrations: `true`
- Requires full redeploy: `false`
- SHA-256: `b213519f17e7b901ebaff2ae5e6040a50f47030952450dcfdef4a17768a07b19`
- Package files: `687`
- Package size: `73,173,378` bytes

## Explicit non-activation statement

This release does **not**:
- change production minimum-supported AppVersion rows;
- enable force-update;
- enable Driver fresh-location enforcement;
- enable the Assistant in production by default;
- change the Assistant from read-only by default.

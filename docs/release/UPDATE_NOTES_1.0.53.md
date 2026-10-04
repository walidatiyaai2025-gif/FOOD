# FOODEX 1.0.53 Release Notes

Status: final C13 distribution after umbrella #858 and integrated gate #873.

## Release identity

- Dashboard: `1.0.53`
- Customer app: `1.0.53+53`
- Driver app: `1.0.53+53`
- Customer runtime/footer identity: `1.0.53`
- Driver runtime/footer identity: `1.0.53`
- Driver diagnostics current identity: `1.0.53`
- Driver diagnostics build identity: `53`
- Published `1.0.52` remains immutable and is not reused.

## Included changes

- Publish C13 umbrella #858 after final integrated gate #873 / PR #890: the complete 13-screen Customer journey is merged and accepted on authoritative main.
- Ship the shared authoritative B2B customer ledger and Customer 360 finance contract with explicit عليك/لك balance direction, credit limit, available credit, purchasing power, partial-payment, overpayment, refund/credit-note and adjustment reconciliation.
- Complete Customer Dashboard, Purchases Report, Top Purchased Products, Invoices, Account Statement, Orders/Tracking, Invoice Details, Products, Product Details, Cart/Checkout and Profile surfaces with visible navigation and authoritative live data.
- Preserve exact store/account isolation, server-side stock/price/credit enforcement, idempotent checkout, AR/EN + RTL/LTR behavior, and explicit loading/empty/error/stale/disconnected states without fake live fallback.
- Synchronize Dashboard, Customer and Driver release identities at 1.0.53 / mobile build 1.0.53+53 without changing production force-update or minimum-version policy.

## Dashboard update bundle

- Target version: `1.0.53`
- Minimum current version: `1.0.6`
- Contains migrations: generated manifest is authoritative.
- Requires full redeploy: `false`
- SHA-256: `9a17ec49caa0e4517169c0fc7a3b673afcca4edc5dc7979dae8c47e8a3956554`.

## Explicit non-activation statement

This release does **not** automatically:
- change production minimum-supported AppVersion rows;
- enable force-update;
- enable Driver fresh-location enforcement;
- enable the Assistant in production.

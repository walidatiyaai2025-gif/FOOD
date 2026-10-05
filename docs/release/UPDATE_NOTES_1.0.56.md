# FOODEX 1.0.56 Release Notes

Status: owner-approved Dashboard update after green #924 / PR #925 integration.

## Release identity

- Dashboard: `1.0.56`
- Customer app: `1.0.56+56`
- Driver app: `1.0.56+56`
- Customer runtime/footer identity: `1.0.56`
- Driver runtime/footer identity: `1.0.56`
- Driver diagnostics current identity: `1.0.56`
- Driver diagnostics build identity: `56`

## Included changes

- Catalog Products UI redesign: compact one-row product grid, contextual action menu, store-configured currency, and preserved backend business rules.
- Customer 360 redesign: Finance-first tabs, secure editable Wholesale Credit Limit backed by `b2b_accounts.credit_limit`, audited updates, and automatic propagation through the existing account-summary/ledger calculations.
- Customer address editing: map picker replaces manual latitude/longitude entry; selecting or moving a pin stores coordinates automatically through the existing address contract.
- B2B Orders UX: prominent Create Order button opens the existing multi-product creation flow in a modal; Arabic status/payment labels are localized while authoritative quote, stock, pricing and order rules remain unchanged.
- Operations Orders cleanup: driver actions are removed from the orders grid while driver business logic remains available in its dedicated management surfaces.
- Reliability fixes integrated with #924: Inspector/CSRF error normalization, bounded Customer/Driver retries and backoff, invoice PDF authorization/runtime hardening, preview unavailable-state handling, live polling cooldowns, and B2B finance query optimization.

## Dashboard update bundle

- Target version: `1.0.56`
- Minimum current version: `1.0.6`
- Contains migrations: `true`
- Requires full redeploy: `false`
- SHA-256: `4e55e484b470a7a783f3baf4f93862efd4746a566294e2810d527cbf2b858d19`

## Explicit non-activation statement

This release does **not** automatically:
- change production minimum-supported AppVersion rows;
- enable force-update;
- enable Driver fresh-location enforcement;
- enable the Assistant in production.

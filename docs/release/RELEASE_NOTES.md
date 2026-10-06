# FOODEX 1.0.58 Release Notes

Status: #984 commercial policy engine implementation candidate. This branch is CI-green/merge-ready only and MUST NOT be merged to main without a new explicit owner command.

## Commercial policy foundation

- Add one backend-authoritative product commercial policy engine with OPEN / RESTRICTED / CLOSED states, channel restrictions, customer/group precedence and server-time availability windows.
- Add multi-unit selling with conversion to the base inventory unit, optional unit price/SKU/barcode and immutable order-line selling-unit snapshots.
- Add per-order/day/week/month/lifetime quotas calculated in base units, with atomic customer/product reservation locking, idempotent reservation identity, consumption and cancellation release.
- Reuse the existing inventory, order and shared audit infrastructure rather than duplicating commercial or notification systems.
- Add focused regression coverage for unit conversion, precedence, yearly availability and quota reservation/release behavior.

## Release identity

- Dashboard: `1.0.58`
- Customer app: `1.0.58+58`
- Driver app: `1.0.58+58`
- No production force-update, minimum-version, notification or store-release policy is changed by #984.

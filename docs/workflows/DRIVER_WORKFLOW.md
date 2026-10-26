# FOODEX Driver Workflow

Status: **Authoritative**
Updated: 2026-10-26

## Ownership

The Driver App is the fulfillment runtime for **B2C / Retail** and for **explicitly assigned B2B / Wholesale Drivers**.

B2B automatic routing remains Van-first. A B2B Driver is never invented as an automatic fallback; B2B Driver work exists only after an authorized manual Order Operations dispatch selects that Driver.

## Runtime contract

1. Driver login requires an authoritative active Driver profile plus the matching `B2C_DRIVER` or `B2B_DRIVER` role and execution permission.
2. The shared Driver assignment APIs are channel-scoped from the authenticated Driver profile.
3. Assignment queries return only work matching the Driver channel/store.
4. B2B Driver assignments may be created only by authorized manual Order Operations dispatch; direct generic admin assignment cannot create them.
5. Delivery actions use server-returned allowed states and backend-authoritative transitions.
6. Push registration and assignment access preserve the authenticated Driver channel.
7. No automatic B2B Driver fallback is permitted; automatic B2B routing remains Van-first.

## Driver lifecycle

The Driver lifecycle remains: assignment -> accepted -> picked up -> out for delivery -> delivered, with failed-delivery/proof/finance behavior governed by backend rules.

For manually assigned B2B work, the Dashboard order is advanced to `out_for_delivery` when the Driver is assigned to a `ready` order. Channel/store authorization, offline/error handling, idempotency, notifications and audit history remain enforced.

## Route authority

Canonical Driver UI functions are declared in `docs/execution/UI_ROUTE_AUTHORITY.json` and validated by `scripts/validate_ui_route_convergence.py`.

The Driver App may continue using the shared assignment route surface; channel authority comes from the authenticated Driver profile. No separate uncontrolled B2B route tree is required.

## Observability

Driver runtime errors are reported as `driver_app` System Inspector events with the authenticated B2B or B2C channel context. Sensitive tokens, personal data and coordinates are sanitized before persistence.

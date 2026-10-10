# FOODEX Driver Workflow

Status: **Authoritative**
Updated: 2026-10-10

## Ownership

The Driver App is the fulfillment runtime for **B2C / Retail only**.

B2B / Wholesale fulfillment belongs to the Van runtime. Legacy B2B Driver identities, routes and assignment behavior are not production execution paths and must not be reintroduced as fallback behavior.

## Runtime contract

1. Driver login requires the authoritative B2C Driver identity/profile.
2. Production navigation is restricted to `/driver/b2c/**`.
3. Assignment queries return only B2C work authorized for that Driver/store.
4. Delivery actions use server-returned allowed states and backend-authoritative transitions.
5. Location heartbeat, notifications, proof, collection/wallet and assignment access remain B2C-scoped.
6. `/driver/b2b/**` is forbidden production route authority.
7. Attempts to assign or execute a B2B order through Driver APIs are rejected; they never trigger a B2B Driver fallback.

## B2C lifecycle

The B2C Driver lifecycle remains intact: assignment -> accepted -> picked up -> out for delivery -> delivered, with failed-delivery/proof/finance behavior governed by the existing backend rules.

Removing B2B execution from Driver must not weaken B2C authorization, offline/error handling, idempotency, notifications or audit history.

## Route authority

Canonical Driver UI functions are declared in `docs/execution/UI_ROUTE_AUTHORITY.json` and validated by `scripts/validate_ui_route_convergence.py`.

The registry must contain only B2C Driver routes and must fail validation if `DriverRoutes.b2b` or `/driver/b2b/` returns to production navigation.

## Observability

Driver runtime errors are reported as `driver_app` System Inspector events with B2C channel context. Sensitive tokens, personal data and coordinates are sanitized before persistence.

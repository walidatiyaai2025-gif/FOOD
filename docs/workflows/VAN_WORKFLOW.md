# FOODEX Van Workflow

Status: **Authoritative**
Updated: 2026-10-10

## Ownership

The Van App is the fulfillment runtime for **B2B / Wholesale only**.

B2B order source does not change this rule: Customer App, Dashboard, Van-assisted order capture and integrations all converge on the same Van fulfillment lifecycle. There is **no Driver fallback for B2B**.

If Smart Routing cannot select an eligible Van, the order stays in `awaiting_dispatch` until an authorized Van dispatch decision is made.

## Canonical lifecycle

1. Routing creates/updates the authoritative B2B dispatch state and Van assignment history.
2. The assigned Van reads B2B work from `GET /api/v1/van/orders` and exact detail from `GET /api/v1/van/orders/{order}`.
3. Execution truth is read from `GET /api/v1/van/orders/{order}/execution`.
4. Server-authorized transitions use `POST /api/v1/van/orders/{order}/execution/transition`.
5. Proof uses `POST /api/v1/van/orders/{order}/execution/proof`.
6. Failed delivery uses `POST /api/v1/van/orders/{order}/execution/fail`.
7. Retry uses `POST /api/v1/van/orders/{order}/execution/retry` and returns the order to the server-authorized `out_for_delivery` state.
8. COD collection uses the authoritative customer/invoice context, posts one idempotent collection and exposes its receipt.
9. Wallet/remittance preserve custody provenance and idempotency.
10. Live location/tracking resolves the active Van for B2B; it never substitutes Driver location.

## Execution states

The delivery-action sequence is server-owned. Current Van actions cover:
- accept;
- pickup;
- out for delivery;
- proof capture/upload;
- delivered;
- failed with configured reason and optional evidence;
- retry from failed;
- collect outstanding COD;
- open posted receipt;
- remit custody balance.

The UI may render only actions authorized by current backend state. Stale UI state must not bypass server validation.

## Finance

Canonical endpoints include:
- `GET /api/v1/van/customers/{type}/{customer}/collection-context`;
- `POST /api/v1/van/customers/{type}/{customer}/collect`;
- `GET /api/v1/van/wallet`;
- `POST /api/v1/van/remittances`.

Collection and remittance require idempotency keys. Over-collection is rejected. COD outstanding blocks Delivered until authoritative settlement clears it. Account-credit outstanding remains non-cash finance truth.

## Route authority and visual evidence

Canonical Van screens/actions are declared in `docs/execution/UI_ROUTE_AUTHORITY.json`. Van production UI changes under `apps/van_app/lib/**` must trigger Required Mobile visual evidence through `.github/workflows/required-ci-gate.yml` and `.github/workflows/mobile-screenshot-capture.yml`.

## Audit and System Inspector

Routing/assignment history and immutable Van execution events provide audit provenance for the order lifecycle. Runtime/API failures are reportable to System Inspector as `van_app` events with safe context such as order, assignment, route, visit, collection or remittance identifiers plus correlation id. Tokens, request/response bodies, personal data and coordinates are sanitized.

## Channel boundary

Van fulfillment authority must not become a B2C fallback. B2C remains Driver-owned.

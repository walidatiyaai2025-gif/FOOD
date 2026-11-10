# FOODEX Driver Workflow

Status: **Authoritative**
Updated: 2026-10-26

## Ownership

The Driver App target fulfillment runtime is **B2C / Retail only**; historical B2B Driver runtime migration is V2 #2939.

B2B is Van-only, with no automatic or manual B2B Driver fallback. Previously assigned B2B Drivers require audited cutover and runtime token restrictions (#2939).

## Runtime contract

1. B2C fulfillment requires an active Driver profile and B2C_DRIVER role. Legacy B2B Driver login/execution must be restricted by #2939.
2. The shared Driver assignment APIs are channel-scoped from the authenticated Driver profile.
3. Assignment queries return only work matching the Driver channel/store.
4. B2B Driver assignments cannot be created by any route, including manual dispatch.
5. Delivery actions use server-returned allowed states and backend-authoritative transitions.
6. Push registration and assignment access preserve the authenticated Driver channel.
7. No B2B Driver fallback of any kind is permitted; B2B is Van-only.

## Driver lifecycle

The Driver lifecycle remains: assignment -> accepted -> picked up -> out for delivery -> delivered, with failed-delivery/proof/finance behavior governed by backend rules.

For B2B work, the Dashboard order must be assigned to Van or held in `awaiting_dispatch`; never via Driver. Channel/store authorization, offline/error handling, idempotency, notifications and audit history remain enforced.

## Route authority

Canonical Driver UI functions are declared in `docs/execution/UI_ROUTE_AUTHORITY.json` and validated by `scripts/validate_ui_route_convergence.py`.

Existing Driver routes remain for historical compatibility, not to authorize B2B fulfillment. Runtime/Flutter denial is V2 #2939.

## Observability

Driver runtime errors are reported as `driver_app` System Inspector events with the authenticated B2B or B2C channel context. Sensitive tokens, personal data and coordinates are sanitized before persistence.

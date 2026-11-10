# FOODEX Driver Workflow

Status: **Authoritative**
Updated: 2026-11-10
Source: V2 #2859 + #2939, P136 fulfillment actor contract

## Ownership

The Driver App is the fulfillment runtime **only for B2C / Retail**. **All B2B / Wholesale fulfillment is Van-only**, including manual dispatch. There is no automatic or manual B2B Driver fallback.

Historical B2B Driver identities, invoices, delivery proofs, driver assignments, and collections stay available to authorized audits and finance reports. They do **not** confer live Driver execution authority. An invalid legacy session or biometric token fails closed; do not delete historical identity as a side effect.

## Runtime contract

1. Driver login requires an active `b2c` Driver profile, `B2C_DRIVER` role, `deliveries.b2c.execute` permission and explicitly scoped Retail store; B2B Driver login/trial login returns a clear authorization denial.
2. The backend Driver runtime middleware independently checks the same B2C-only rule on **each** request, including tokens issued before cutover; old B2B bearer tokens cannot read or mutate live Driver assignments, proof, status, collection, wallet, or location routes.
3. Driver assignment and dispatch APIs accept **B2C only**; never accept forged `customer_service_override` for B2B. B2B manual and automatic dispatch use only the Van actor. When none is eligible, keep `awaiting_dispatch` with no Driver fallback.
4. Driver push registration and execution notifications are for B2C recipients only. Historic B2B push receipts/tokens are preserved for audit or revoked through explicit safe lifecycle, but may not authorize new B2B work.
5. B2C lifecycle remains assignment → accepted → picked up → out for delivery → delivered, with server-defined `available_statuses`, proof, failure/retry, finance receipts, idempotency and store isolation.

## Driver App UX

The Flutter Driver App refuses B2B Driver login responses and stored/biometric sessions, removes stale local credentials and presents localized Arabic/English instructions that Wholesale execution belongs to the Van App. Old B2B deep links do not open execution routes. Retail B2C remains accessible with existing compact responsive/RTL/LTR UI.

## Route authority

Canonical B2C Driver UI functions are in `docs/execution/UI_ROUTE_AUTHORITY.json`. B2B route constants may remain for decoding old local links only; they must not be advertised or navigable as live operations. Authorization is enforced on server independently of Flutter.

## Observability

Driver B2C runtime failures can be sent to System Inspector as `driver_app` events with scrubbed correlation context. Historical Wholesale execution belongs to financial/audit exports, not the live Driver runtime.

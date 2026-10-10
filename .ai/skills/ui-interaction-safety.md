# Skill: FOODEX UI Interaction & Mutation Safety

Use for any UI that creates, edits, deletes, submits, transitions, pays, collects, remits, assigns, dispatches, uploads proof or triggers a business side effect.

## 1. Core invariant

A button press is a request, not business truth.

The backend/domain service remains authoritative for:
- order state;
- assignment state;
- finance/ledger/custody;
- stock/pricing/offers;
- visit completion;
- permissions;
- payment result.

The UI may display optimistic progress only when the contract safely supports reconciliation.

## 2. Duplicate-submit protection

For state-changing operations:
- disable or lock the triggering control while the request is in flight;
- prevent simultaneous duplicate submits;
- show a visible busy/progress state;
- do not create a new mutation merely because the user tapped twice.

If the backend/API supports idempotency, use it.

Current FOODEX examples include:
- Retail checkout submission guard;
- Driver wallet contracts;
- Van collection/remittance/order contracts.

## 3. Idempotency and retry

For retriable high-risk operations:
- preserve the same idempotency key for a retry of the **same payload**;
- generate a new key when the material payload changes;
- never create duplicate ledger rows/orders/notifications/transitions due to network retry;
- distinguish a network timeout from a confirmed failure when backend outcome is unknown;
- reconcile from authoritative state before offering another unsafe mutation.

High-risk examples:
- checkout/order creation;
- payment callback-facing action;
- collection/remittance;
- assignment/lifecycle transition;
- proof submission;
- bulk action.

## 4. Mutation state machine

Model relevant states explicitly:

`idle -> validating -> submitting -> success | recoverable_error | terminal_error`

For live records also consider:
`stale/reloading/reconciled`.

Rules:
- error messages belong near the owning action/field when practical;
- success refreshes/reconciles the visible authoritative record;
- a retry cannot silently submit a different payload;
- leaving the page during an in-flight mutation must not create an ambiguous second submission.

## 5. Destructive action

Delete/cancel/revoke/clear:
- must be permission checked server-side;
- requires deliberate confirmation when impact is meaningful;
- confirmation must name the business object/action, not generic "Are you sure?";
- preserve immutable audit/ledger history;
- scoped delete removes only records the business rule actually allows.

## 6. Unsaved / dirty state

For substantial create/edit/wizard flows:
- track whether meaningful data changed;
- warn before discarding meaningful unsaved work;
- do not warn on untouched forms;
- after successful save, clear dirty state and return to the correct working context.

## 7. Action visibility vs authorization

Hiding an action is UX, not security.

PASS requires:
- unauthorized actions are not presented unnecessarily;
- direct requests are still rejected server-side;
- exact record/store/channel/tenant scope is validated.

## 8. Offline behavior

Never queue a business-critical mutation locally unless the product explicitly defines safe offline synchronization.

When offline:
- read-only last-confirmed state may remain with visible stale/offline treatment;
- unsafe financial/order/assignment transitions should fail clearly or wait for authoritative connectivity.

## 9. Testing matrix

For a critical mutation test:
- valid success;
- validation failure;
- authorization/scope failure;
- simultaneous double submit;
- network failure then retry;
- same-payload idempotency;
- changed-payload new idempotency identity;
- authoritative refresh after success;
- stale record changed by another actor when relevant.

## 10. Completion gate

A mutation UI is not complete merely because the button works once.

PASS requires duplicate safety, server authority, correct busy/error/success state, authorization, retry semantics and authoritative reconciliation.

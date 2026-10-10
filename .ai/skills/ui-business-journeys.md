# Skill: FOODEX Business UI Journeys

Use this skill for every page/screen request after route preflight and before visual composition.

FOODEX UI is a business workflow, not a collection of cards.

## Production casebook

Read:
- `.ai/uiux/business-casebook.json`
- `.ai/uiux/business-recipes.json`

The casebook is learned only from current production source + regression tests. It currently covers:
- Dashboard Order Operations / dispatch;
- Customer 360;
- unified Driver/Van live tracking;
- Van finance support/reconciliation;
- Customer wholesale orders;
- Customer wholesale cart/checkout;
- Driver active delivery;
- Driver wallet/remittance;
- Van routes/visits;
- Van order builder;
- Van customer collection;
- Van remittance.

## Mandatory business journey lock

Before implementation, lock:

```text
Business case:
Reusable recipe:
Actor:
Permission:
Store/channel/tenant scope:
Record identity:
Authoritative data/service:
Primary action:
Allowed transitions:
Mandatory states:
Mutation/retry/idempotency rule:
Post-success reconciliation:
Exact production sources:
Exact regression tests:
```

If the prompt maps to an existing case, inspect those source files before inventing behavior.

## Real FOODEX rules learned from production

### Van collection
- customer + invoice are selected from current Van scope;
- amount must be valid and cannot exceed outstanding invoice balance;
- same-payload retry preserves idempotency identity;
- background refresh may retain data only with visible stale treatment;
- success reloads authoritative collection context.

### Van remittance
- amount cannot exceed `availableToRemit`;
- same operation retry keeps its idempotency key;
- offline/409/422 are materially different outcomes;
- a timeout does not equal success;
- success reloads the wallet.

### Van order builder
- a real customer and non-empty lines are required;
- quantities follow product ordering increments;
- review is preceded by an authoritative server quote;
- client totals never replace the authoritative quote.

### Driver active journey
- assignment identity + channel scope are authoritative;
- live refresh has lifecycle ownership;
- a busy assignment blocks duplicate transitions;
- stale retained assignments are visibly stale/offline.

### Dashboard operations
- row actions target the exact record;
- assignment choices are scoped to the business context;
- status transitions come from backend-authorized next states;
- multiple actions live in the compact overflow pattern.

## New business journey rule

A new reusable journey may be added to the casebook only when:
1. production source exists;
2. authoritative route/context is understood;
3. regression test(s) exist;
4. source markers can prove the casebook is still aligned;
5. state/action invariants are reusable beyond one screenshot.

Do not teach the AI speculative behavior from mockups or chat text alone.

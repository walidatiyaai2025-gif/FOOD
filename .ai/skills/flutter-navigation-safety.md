# Skill: Flutter Navigation and Transient UI Safety

Use when Dart changes touch Navigator/router APIs, dialogs, bottom sheets, snackbars, drawers or context-driven navigation.

## Context lifetime
Do not use a BuildContext across an async gap unless its mounted lifetime is verified at the point of use.

## Navigation semantics
- avoid duplicate pushes from repeated taps/listeners;
- preserve the current route authority and persistent navigation model;
- return typed/intentional results from dialogs/routes when callers depend on an outcome;
- do not pop an unrelated route merely to dismiss transient UI;
- preserve Android back behavior and modal dismissal semantics.

## Dialogs / sheets / snackbars
Every blocking/transient surface must have an intentional dismissal path. Long-lived success/action snackbars must not trap the user; dialogs that can be cancelled need a visible/semantic cancel route when the product contract allows cancellation.

## Async actions
For save/order/payment/etc. flows, separate request completion from route transitions. Confirm authoritative success before leaving the page when required and prevent repeated navigation while the action is in flight.

## Tests
Cover the relevant route/result/dismissal contract, including repeated tap or dispose-before-completion when applicable.

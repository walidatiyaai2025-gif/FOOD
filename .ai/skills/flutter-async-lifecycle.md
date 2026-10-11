# Skill: Flutter Async and Lifecycle Safety

Use when Dart changes introduce or modify Future/await, timers, streams, controllers, notifiers, listeners or lifecycle callbacks.

## Async correctness
- never assume a Widget/State is still mounted after an async gap;
- after an `await`, guard UI work with the correct mounted check before `setState`, Navigator, ScaffoldMessenger, dialogs or inherited-context reads;
- prevent double-submit/re-entrant mutations while a request is active;
- define how stale/out-of-order responses are ignored when requests can overlap;
- do not leave intentionally fire-and-forget Futures ambiguous: make intent explicit and ensure failures are handled.

## Lifecycle ownership
Anything created/owned by a State/object must have a matching lifecycle:
- Timer -> cancel;
- StreamSubscription -> cancel;
- controller/notifier/focus/scroll/animation object -> dispose when owned;
- listener -> remove using the same callback/owner;
- HTTP/client/resource -> close when the owning abstraction requires it.

Do not dispose borrowed/shared objects that the State did not create.

## State updates
- do not call `setState` after disposal;
- avoid `setState` for values whose owner is an external notifier/state object;
- keep `initState`, `didChangeDependencies`, `didUpdateWidget`, app-resume and `dispose` responsibilities distinct.

## Tests
Add regression coverage for the relevant hazard: dispose-during-request, repeated tap, stale response ordering, timer/subscription cancellation or resume refresh.

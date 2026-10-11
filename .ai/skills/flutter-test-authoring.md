# Skill: Flutter Test Authoring

Use for every Customer/Driver/Van Dart change.

## Regression-first
A bug fix should reproduce the defect with a failing/stable test before or with the fix whenever practical. A feature should prove the new contract at the smallest reliable level.

## Hermeticity
- no production HTTP/network dependency;
- no real authentication, push, location or payment side effects;
- freeze/inject time for time-sensitive logic;
- do not depend on test execution order;
- clean up timers, streams, controllers and overrides.

## Stable widget tests
- pump only what the interaction needs;
- use `pumpAndSettle` only when the surface actually converges; never use it to mask a periodic timer/animation;
- select widgets by stable semantics/keys/text contract rather than fragile tree position;
- test both AR/EN or directionality when the changed layout/localization contract requires it.

## Scope
Run focused affected tests first, then the app's normal test set. Screenshot/evidence tests remain governed by their dedicated workflows and should not be casually folded into the default hermetic suite.

## Assertions
Assert business-visible outcomes and authoritative state, not merely that a callback executed or an HTTP call returned.

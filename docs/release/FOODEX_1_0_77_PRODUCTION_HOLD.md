# FOODEX 1.0.77 Production Release Hold

Status: **HOLD**

Parent program: #1380

FOODEX 1.0.76 is superseded as the production-launch target. This hold exists to stop any 1.0.76 production publication from becoming the final launch line while the reversible 1.0.77 program is implemented.

## Hard Customer freeze

Frozen baseline commit:

`4de5a24b2ca6bd9b448a79ca05f7f44c7d428f4d`

Immutable path:

`apps/customer_app/**`

No worker may modify any Customer source, test, asset, pubspec, dependency, localization, generated file, formatting, UI, UX, navigation, business logic or runtime behavior.

The hold may be removed only when #1380 is ready for final composition and the Customer tree is proven identical to the frozen baseline.

## Release behavior while HOLD is present

- repository root release identity may advance to 1.0.77 for coordination;
- synchronized mobile-version assertions are intentionally not used as a reason to edit the frozen Customer app;
- release readiness must prove the Customer tree is unchanged;
- the canonical Release Factory must not successfully publish a final synchronized release until Worker 1 / #1381 and Worker 7 / #1387 replace the temporary hold contract with the final frozen-lineage release contract;
- no 1.0.76 artifact is the final production-launch deliverable.

This file is an explicit non-production coordination state, not launch approval.

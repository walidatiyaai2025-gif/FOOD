# Skill: FOODEX UI Casebook Learning

Use when a real production bug, feature or acceptance failure teaches a reusable UI/business rule.

## Evidence threshold

Promote a lesson into `.ai/uiux/business-casebook.json` or `business-recipes.json` only when supported by:
- production source;
- authoritative business behavior;
- at least one regression/contract test;
- stable source markers or another deterministic drift check.

Issue discussion or screenshot preference alone is not enough.

## What belongs in a case

Record:
- actor/scope;
- canonical business journey;
- page archetype;
- real production sources;
- real tests;
- mandatory states;
- authoritative actions/transitions;
- retry/idempotency/stale/offline behavior;
- post-success reconciliation;
- anti-patterns.

## Promotion model

1. One-off visual preference -> keep in the owning screen/contract.
2. Repeated visual/component rule -> shared UI skill/pattern library.
3. Reusable business interaction rule -> business recipe.
4. Concrete production example of that rule -> business casebook.
5. Machine-detectable regression -> CI/audit rule.

## Anti-drift

Run:

```bash
python3 scripts/foodex-ui-intent-plan.py --validate-only --output artifacts/foodex-ui-business-casebook-audit.json
```

Validation fails if:
- a referenced production source/test disappears;
- a source marker disappears;
- a recipe/archetype reference is invalid;
- a registered canonical function no longer exists.

This keeps "learning" tied to repository truth rather than chat memory.

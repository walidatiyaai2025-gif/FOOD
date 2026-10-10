# Skill: FOODEX UI Structural Parity

Use for every new page/screen and for material layout refactors.

## 1. Purpose

FOODEX visual parity is not pixel cloning. A production page must share the same structural language:
- canonical shell/theme;
- compact header hierarchy;
- shared state/action primitives;
- localized business copy;
- surface-appropriate density and lifecycle behavior.

The machine-readable contract is `.ai/uiux/structural-signatures.json`.

## 2. New-screen hard gate

A newly added user-facing screen/page must satisfy the minimum signature for its surface.

Run:

```bash
python3 scripts/foodex-ui-structure-audit.py \
  --base <base-sha> \
  --head <head-sha> \
  --report artifacts/foodex-ui-structure-audit.json
```

Missing a hard required group is a merge blocker.

## 3. Existing-screen similarity

Changed existing screens are compared against the surface's Golden Pages using structural marker overlap.

This score is **advisory**:
- low similarity is a prompt to inspect the nearest Golden Page;
- it must not force an operationally valid legacy screen into the wrong archetype;
- runtime visual evidence remains authoritative for actual appearance.

## 4. Anti-drift

The audit also checks durable shared markers in:
- Dashboard brand/components;
- Customer V3 shared primitives;
- Driver theme/active journey;
- Van tokens/actions/foundation.

If a shared marker intentionally changes, update the registry/skill layer in the same PR.

## 5. Completion

PASS requires:
- all new screen hard signatures pass;
- no shared-system drift error;
- low similarity warnings are reviewed against the correct archetype;
- runtime evidence passes for the affected surface.

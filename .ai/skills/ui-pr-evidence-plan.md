# Skill: FOODEX UI PR Evidence Plan

Use before requesting merge for any UI change.

## 1. Generate exact-diff plan

```bash
python3 scripts/foodex-ui-pr-plan.py \
  --base <base-sha> \
  --head <head-sha> \
  --strict \
  --output artifacts/foodex-ui-pr-evidence-plan.json
```

The plan derives from the real PR diff and publishes:
- affected UI surfaces;
- changed/new screens;
- Golden Page candidates;
- route/context/quality gates;
- visual locales/viewports/states;
- Required CI visual job;
- evidence-inventory changes required for new screens.

Before merge, cross-check the PR against `docs/design-reference/UI_PLACEMENT_SCREEN_PATTERN_CONTRACT.md`. The PR must record the final canonical parent/route, placement type, primary pattern and **Reuse / Extend / Compose / New Screen** decision. If it adds a new screen, the PR must state why the first three options are insufficient. If navigation changes, record the navigation impact and business-domain owner.

Responsive/locale evidence is explicit, not implied:
- **Desktop** — compact desktop and normal desktop composition as applicable;
- **Tablet** — 768px-class composition;
- **Mobile** — applicable narrow widths and no missing business capability;
- **Arabic / RTL** — runtime/layout evidence;
- **English / LTR** — runtime/layout evidence;
- required permission and loading/empty/error/disabled/stale states where applicable.

## 2. New screen evidence inventory

A new user-facing screen is not accepted without adding it to runtime evidence capture.

For a new:
- Dashboard page -> update `scripts/capture_web_screenshots.mjs`;
- Customer screen -> update Customer `screenshot_evidence_test.dart`;
- Driver screen -> update Driver `screenshot_evidence_test.dart`;
- Van screen -> update Van `screenshot_evidence_test.dart`.

This prevents "new screen exists but visual CI never sees it".

## 3. Required visual jobs

UI runtime evidence is part of Required CI:
- Dashboard changes -> `dashboard-visual-evidence`;
- Customer/Driver/Van changes -> `mobile-visual-evidence`.

A green generic unit-test gate cannot substitute for these jobs.

## 4. Evidence matrix

Read `.ai/uiux/visual-evidence-matrix.json`.

At minimum, use the matrix's AR/EN and viewport expectations. Add the conditional state that proves the task:
- empty/error;
- stale/offline;
- menu/modal/sheet;
- map/chart;
- transaction/assignment state.

## 5. Completion

PASS only when the exact PR head has:
- placement/pattern decision recorded and consistent with route authority;
- new-screen/Sidebar justification present when applicable;
- Desktop + Tablet + Mobile evidence present for the affected responsive surface;
- Arabic/RTL + English/LTR evidence present;
- structural audit PASS;
- evidence-plan strict validation PASS;
- applicable Required CI visual job PASS;
- functional/authorization/mutation gates PASS.

# Skill: FOODEX UI/UX Evidence Gate

Use after `.ai/skills/uiux-audit.md` for visual/interaction work.

The goal is reproducible proof tied to the exact implementation SHA, not screenshots detached from source.

## 1. Evidence identity

For every evidence set record:
- Issue / PR;
- exact HEAD SHA;
- surface;
- route/screen;
- actor/role/store/channel;
- locale;
- direction;
- viewport/device size;
- data state (loaded/empty/error/stale/etc.);
- interaction being proved.

Evidence from an older source SHA becomes stale when relevant implementation code changes.

## 2. Dashboard runtime evidence

Current FOODEX web visual QA uses:
- deterministic screenshot seeders;
- a real Laravel runtime;
- Playwright/Chromium;
- `scripts/capture_web_screenshots.mjs`;
- `.github/workflows/ui-visual-qa.yml`.

Current responsive evidence includes representative:
- desktop 1280;
- compact 1024;
- tablet 768;
- mobile 390;
- Arabic and English.

For a new or changed Dashboard page, ensure the capture script contains the route/state needed to prove the requirement.

Evidence should prove the applicable items:
- canonical shell;
- header/tabs/actions;
- loaded business data;
- row-action menu open when that interaction matters;
- modal/drawer open when direct-record management matters;
- AR/EN;
- responsive behavior;
- map/chart/state truthfulness when relevant.

## 3. Mobile runtime evidence

Current `FOODEX Mobile Screenshot Capture` runs:

```bash
flutter test test/screenshot_evidence_test.dart
```

for Customer, Driver and Van.

Current standard portrait geometry:
- **430×932**

Current Van compact geometry:
- **360×800**

The workflow validates portrait geometry and uploads the screenshot artifacts.

When a new page/screen is added:
- add it to the surface screenshot evidence inventory/harness when visual acceptance is required;
- capture AR and EN;
- capture populated/critical state needed by the acceptance contract;
- add compact evidence when density/responsive behavior is material.

## 4. Workflow-trigger coverage is a hard requirement

A visual workflow that never runs for the changed file is not evidence.

Before completion inspect path triggers in:
- `.github/workflows/mobile-screenshot-capture.yml`
- `.github/workflows/ui-visual-qa.yml`
- other owning screenshot/evidence workflow

If the new screen/component path is not covered and the UI change requires that workflow:
- update the workflow path filters in the same PR, or
- move the change under an already-correct broad trigger if that is the repository design.

Do not say "visual CI did not run, therefore it is not required" when the path filter is simply incomplete.

## 5. Static evidence is not runtime evidence

These are useful but insufficient alone for a visual interaction:
- source grep;
- HTML/widget snapshot;
- closed Issue;
- unit test with no rendered interaction;
- generic required CI.

For a visual/interaction requirement, pair:
- source/contract test;
- real rendered runtime evidence;
- functional interaction test when the requirement includes behavior.

## 6. State coverage

Capture the state that proves the requirement, not only the prettiest loaded screen.

Depending on the task include:
- loaded/populated;
- empty;
- error;
- offline/stale;
- open overflow menu;
- open modal/drawer/bottom sheet;
- selected tab/filter;
- map with real available points and missing-location truthfulness;
- notification/deep-link exact target;
- narrow layout.

If one screenshot cannot prove the interaction, use multiple captures or a functional test.

## 7. Screenshot evidence repository contract

FOODEX also has the runtime screenshot evidence guard and manifest.

Do not manually replace generated evidence metadata to make it pass. Regenerate through the owning harness and keep source/evidence lineage consistent.

When committed screenshot evidence is required, verify its audit/manifest tooling rather than editing counts by hand.

## 8. Final visual review questions

Before accepting evidence ask:
- Is this the exact current HEAD?
- Is this the correct role and route?
- Is the data state real/deterministic and clearly identified?
- Are AR and EN both represented?
- Is the narrow layout represented where needed?
- Does the screenshot actually show the action/state being claimed?
- Could a stale value be mistaken for live?
- Is any important action clipped/hidden?
- Is there mixed-language UI?
- Does the page look like the same FOODEX product as adjacent screens?

Any "no/unknown" means more evidence is required.

## 9. Authority and mutation evidence

Visual proof must be paired with the product gate that owns the behavior.

When routes/navigation changed, include the current authority/navigation test for the surface.

When a state-changing action changed, functional evidence should prove as applicable:
- one submit produces one business mutation;
- duplicate tap is blocked/deduplicated;
- retry semantics are safe;
- server authorization/scope still applies;
- success reconciles authoritative state;
- error/offline does not fabricate success.

A screenshot of a green success message is not proof that only one order/payment/ledger transition occurred.

## 10. Performance evidence

For high-growth/live surfaces, record the relevant non-visual evidence:
- pagination contract/server paginator;
- load-more/incremental list behavior;
- request/timer lifecycle test;
- stale-response/race control when relevant.

Performance acceptance cannot be inferred from a static screenshot.

## Phase 3 — exact-diff evidence planning

Use `.ai/uiux/visual-evidence-matrix.json` and generate:

```bash
python3 scripts/foodex-ui-pr-plan.py --base <base> --head <head> --strict --output artifacts/foodex-ui-pr-evidence-plan.json
```

For newly added screens, update the owning capture inventory in the same PR. Dashboard and mobile visual workflows are invoked from Required CI for actual UI-code changes.


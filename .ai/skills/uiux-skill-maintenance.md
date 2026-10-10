# Skill: FOODEX UI/UX Skill Maintenance & Anti-Drift

Use whenever a PR changes durable UI architecture, tokens, shared components, canonical routes, evidence workflows or acceptance tests.

## 1. Source remains authoritative

The skills summarize production. They do not freeze production.

If current source changes:
- update the source first for a valid product requirement;
- update the relevant skill/registry in the same PR when the change is durable;
- do not force source to match an obsolete skill summary.

## 2. Changes that require skill review

Review the skill layer when changing:
- Dashboard brand tokens/shared components/shell/sidebar;
- Customer/Driver/Van theme or shared primitives;
- canonical route/navigation authority;
- Van production screen inventory;
- page archetype;
- mandatory pagination/state contract;
- localization contract;
- screenshot/visual-evidence workflow;
- UIUX static audit or requirement matrix;
- new reusable failure lesson discovered by CI/runtime QA.

## 3. Machine-readable policy synchronization

Keep these aligned with production:
- `.ai/uiux/component-registry.json`
- `.ai/uiux/page-archetypes.json`
- `.ai/uiux/golden-pages.json`
- `.ai/uiux/forbidden-patterns.json`
- `.ai/uiux/quality-gates.json`
- `.ai/uiux/context-contract.json`
- `.ai/uiux/uiux-scorecard.json`
- `.ai/uiux/ui-lessons.json`
- `.ai/uiux/visual-evidence-matrix.json`
- `.ai/uiux/structural-signatures.json`

If a referenced source/test moves, policy validation must fail until corrected.

## 4. Lesson promotion

A recurring real failure should become a durable lesson only when supported by:
- a real bug/CI/runtime incident;
- a clear reusable rule;
- a regression test or deterministic detection path where practical.

Do not fill skills with speculative style preferences.

## 5. Audit cadence

Re-run a skill audit when:
- a major UI mission converges;
- a design-system migration lands;
- route authority changes materially;
- visual QA misses a regression;
- the owner asks to strengthen the skills.

Compare skills against **current main/branch source**, not old chat memory.

## 6. Anti-bloat rule

A stronger skill is not necessarily a longer skill.

Prefer:
- one authoritative rule;
- exact source path;
- exact test/evidence gate;
- machine-readable registry where useful.

Remove duplicated prose when a shared skill can own the invariant.

## 7. Completion

A design-system/route/evidence contract change is incomplete if the repository-native skill layer now teaches a materially stale implementation.

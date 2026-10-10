# Skill: FOODEX UI/UX — Master Router

Use this skill for **every request that creates, redesigns, splits, refactors, or materially changes a page/screen** in FOODEX.

Examples:
- "اعمل صفحة"
- "صمم شاشة"
- "قسم الصفحة Tabs"
- "زود زرار/فلتر/جدول"
- "خلي الصفحة شبه باقي فود"
- "اعمل Dashboard screen"
- any Customer / Driver / Van UI work

This skill is a router. It does not replace the surface-specific skill.

Also read `.ai/skills/ui-pattern-library.md`. It maps the real production classes/widgets and source files that must be inspected before inventing UI.

Cross-cutting quality skills:
- `.ai/skills/ui-route-authority.md` — mandatory for every page/route/navigation change;
- `.ai/skills/ui-accessibility.md` — mandatory for every user-facing UI;
- `.ai/skills/ui-interaction-safety.md` — mandatory when the UI mutates business state;
- `.ai/skills/ui-performance.md` — mandatory for lists/grids/search/live/polling/high-growth data;
- `.ai/skills/uiux-skill-maintenance.md` — use when durable UI architecture/contracts change.
- `.ai/skills/ui-structural-parity.md` — hard minimum signature for new screens + Golden structural similarity.
- `.ai/skills/ui-pr-evidence-plan.md` — exact-diff runtime evidence plan and inventory enforcement.
- `.ai/skills/ui-business-journeys.md` — production-backed business flows, states and invariants.
- `.ai/skills/ui-intent-routing.md` — resolve short AR/EN owner prompts into bounded FOODEX journeys.
- `.ai/skills/ui-casebook-learning.md` — promote only source+test-backed reusable business lessons.

## 0. Authority order

Before UI code, read current files from the branch you are editing. Current source wins over snapshots in this skill.

1. `AGENTS.md`
2. live Issue / branch / PR / exact-head CI
3. `docs/design-reference/DASHBOARD_UI_UX_CONTRACT.md`
4. `docs/quality/LOCALIZATION_CONTRACT.md`
5. current surface-specific design target / requirement matrix
6. current production theme/tokens/components
7. this skill

Never invent a new visual language because a prompt only says "make a page".

## 1. Route the task to the correct FOODEX skill

### Laravel Management Dashboard / Admin / business web
Read:
- `.ai/skills/dashboard-uiux.md`
- `docs/design-reference/DASHBOARD_UI_UX_CONTRACT.md`
- `docs/design-reference/FOODEX_VISUALIZATION_CONTRACT.md` when charts/KPIs are involved

### Customer Flutter app
Read:
- `.ai/skills/customer-uiux.md`
- `docs/design-reference/CUSTOMER_UIUX_APPROVED_TARGET.md`

### Driver Flutter app
Read:
- `.ai/skills/driver-uiux.md`
- current Driver v4.2 evidence/route inventory when in scope

### Van Flutter app
Read:
- `.ai/skills/van-uiux.md`
- `docs/design-reference/VAN_UIUX_APPROVED_TARGET.md`
- `docs/design-reference/VAN_DASHBOARD_PARITY_CONTRACT.md` when capability parity matters

If a feature spans surfaces, apply every relevant skill. Do not average their tokens together.

## 2. Mandatory page-definition pass before coding

Write down internally, from the Issue and current product:
- surface/app;
- actor + permission;
- canonical route;
- normal navigation entry;
- page business purpose;
- authoritative data source;
- primary action;
- row/record actions;
- filters/search;
- states: loading, loaded, empty, error, stale/offline as applicable;
- AR/EN labels and RTL/LTR behavior;
- narrow/wide responsive behavior;
- exact existing shared component(s) to reuse.

A page is incomplete if it is only reachable by a hidden/deep route when normal navigation is required.

## 3. Reuse-first rule

Before creating a new component, search the current surface for:
- shared shell;
- page header;
- tabs;
- cards;
- buttons;
- badges/status chips;
- filters;
- grid/list row pattern;
- empty/error/loading states;
- modal/drawer/bottom sheet;
- navigation/footer;
- visualization primitive.

If an existing FOODEX primitive satisfies the requirement, reuse it.

Do not:
- introduce per-screen brand colors;
- add a second design system;
- paste default Bootstrap/Material styling that conflicts with production;
- clone an existing component with slightly different spacing;
- hard-code locale text inside reusable UI primitives.

## 4. Universal FOODEX v4.2 interaction rules

Across surfaces, where applicable:
- compact/data-first composition;
- meaningful business labels, not raw DB IDs/keys/JSON;
- exact-record actions, not redirects to a generic list;
- one compact ellipsis overflow for row actions rather than many competing row buttons;
- clear primary action hierarchy;
- order/reference identifiers stay visually unambiguous and do not wrap on mobile;
- live/dynamic data refreshes according to the owning surface contract;
- retained old data is visibly stale/offline, never silently presented as current;
- AR/RTL and EN/LTR preserve the same information hierarchy;
- no fake/sample records in production runtime;
- visual acceptance requires real runtime evidence when the owning requirement calls for it.

## 5. Localization is part of layout

Every page must survive:
- Arabic RTL;
- English LTR;
- translated status/state/channel/role/payment/unit labels;
- longer translated strings;
- no mixed-language system labels.

Do not "fix" RTL by maintaining a second divergent screen.

## 6. Page completion checklist

Before claiming a page complete:
- uses the authoritative surface shell/theme;
- uses shared components before bespoke ones;
- business navigation reaches it;
- primary action is obvious;
- filters are compact;
- actions target exact records;
- empty/error/loading/stale/offline states are explicit where relevant;
- no user-facing raw technical identifiers;
- AR/EN and RTL/LTR pass;
- no horizontal overflow except intentional contained scroll regions;
- responsive behavior is verified at the surface's real target widths;
- tests protect the important composition/interaction contract;
- runtime evidence is captured when required by the Issue/mission.

## 7. Rule for prompt shorthand

When the repository owner says only **"اعمل صفحة X"**, interpret that as:

> Build X as a production FOODEX page using the existing canonical route/domain architecture, current FOODEX design tokens and shared components, AR/EN + RTL/LTR, responsive/data-first states, exact-record actions and current v4.2 UI/UX acceptance rules.

The owner does not need to repeat these UI/UX rules in every prompt.

## 8. Production fingerprint — mandatory, not optional

Before building a page, identify the exact production fingerprint for its surface. Do not infer styling from memory.

### Dashboard fingerprint
Use these files directly:
- `backend/resources/views/admin/_brand.blade.php`
- `backend/resources/views/admin/_brand-components.blade.php`
- `backend/resources/views/admin/shell.blade.php`
- `backend/public/assets/admin/foodex-visualization.css` when visualizations are present

The current real Dashboard shell/components define the visual language. Default Bootstrap markup is not a FOODEX design system.

### Customer fingerprint
Use:
- `apps/customer_app/lib/core/theme/customer_ui_v3_tokens.dart`
- `apps/customer_app/lib/core/theme/foodex_theme.dart`
- `apps/customer_app/lib/shared/customer_ui_v3/customer_components.dart`
- `apps/customer_app/lib/shared/customer_ui_v3/customer_states.dart`
- `apps/customer_app/lib/shared/customer_persistent_footer.dart`

### Driver fingerprint
Use:
- `apps/driver_app/lib/core/theme/foodex_theme.dart`
- `apps/driver_app/lib/features/delivery/active/driver_active_journey.dart`
- `apps/driver_app/lib/navigation.dart`

### Van fingerprint
Use:
- `apps/van_app/lib/core/theme/foodex_van_theme.dart`
- `apps/van_app/lib/features/foundation/van_foundation_screen.dart`
- `apps/van_app/lib/features/foundation/van_screen_inventory.dart`
- `apps/van_app/lib/shared/van_action_button.dart`

## 9. Reference-screen selection algorithm

Before markup/widgets:

1. search `docs/design-reference/SCREEN_MANIFEST.json`;
2. select the same role + platform + closest business archetype;
3. inspect the current production implementation for that route/domain;
4. use the production implementation as behavior authority and the approved reference as visual intent;
5. record the selected reference internally before coding.

The manifest currently contains 46 approved reference entries:
- 13 B2B Customer mobile;
- 12 B2C Customer mobile;
- 10 B2B Super Admin web;
- 11 B2C Admin web.

Do not copy fake data or obsolete navigation from a mockup. The reference informs composition; current production contracts own data and behavior.

## 10. No-improvisation rule

A short request such as `اعمل صفحة` grants no permission to invent:
- new colors;
- new card radius;
- a new sidebar/footer;
- new table/list styling;
- new status-chip semantics;
- a second form system;
- a second modal system;
- new spacing scale;
- per-page Material/Bootstrap defaults;
- arbitrary mobile navigation.

If the needed primitive does not exist, first prove that the current production primitives cannot express the requirement. Then extend the shared system, not only one page.

## 11. Page recipes

After this router, read `.ai/skills/page-patterns.md`. It contains the canonical composition recipes for list/management, detail/manage, form/create, dashboard/KPI, Customer commerce, Driver operational and Van field-operation pages.


## 12. Mandatory UI execution pipeline

Every UI task follows this sequence. Skipping a stage is not a shortcut.

### Stage A — Route + Reference Lock
Read `.ai/skills/ui-route-authority.md` and identify:
- surface;
- function;
- role/permission;
- canonical route/renderer;
- normal navigation entry;
- existing authority test;
- page archetype;
- nearest production screen/reference;
- exact production token/theme/component sources.

A second renderer/route for an already-owned function is a regression unless the task explicitly replaces the canonical authority.

### Stage B — Component Map
Read `.ai/skills/ui-pattern-library.md` and list the exact existing primitives used for:
- shell/navigation;
- page header;
- primary/secondary action;
- filter/search;
- row/card/grid;
- row actions;
- states;
- detail/modal/sheet;
- visualization if any.

If the design requires many new primitives, re-check the nearest production pattern before coding.

### Stage C — Interaction / Scale Contract
Before coding mutations or high-growth/live data:
- read `.ai/skills/ui-interaction-safety.md` for create/edit/delete/submit/transition/finance operations;
- read `.ai/skills/ui-performance.md` for lists, grids, search, pagination, maps, analytics or polling;
- read `.ai/skills/ui-accessibility.md` for input modality/semantics.

### Stage D — Production Build
Implement with authoritative data/permissions/business transitions. Do not style a generic CRUD screen first and retrofit FOODEX later.

### Stage E — Mandatory Self-Audit
Run `.ai/skills/uiux-audit.md`.

Any applicable FAIL / UNKNOWN / NOT CHECKED means the page is not complete.

### Stage F — Evidence Gate
Run `.ai/skills/uiux-evidence.md` for visual/interaction work.

Evidence must belong to the current exact source lineage. Old screenshots from a previous relevant SHA are stale.

## 13. No completion claim without UI audit

For any page/screen task, the worker may say "complete", "done", "جاهز", or close the owning UI Issue only when:

1. applicable automated feature/design guards pass;
2. `uiux-audit.md` verdict is PASS;
3. required AR/EN and responsive runtime evidence exists;
4. evidence workflow/path triggers actually cover the changed UI;
5. no required interaction is still UNKNOWN.

Generic green CI alone is not a substitute for this UI acceptance gate.

## 14. Machine-readable UI/UX policy

The prose skills are paired with executable policy under `.ai/uiux/`:

- `component-registry.json` — exact current shared production sources/primitives;
- `page-archetypes.json` — page archetype requirements;
- `golden-pages.json` — production pages/screens to inspect before creating a composition;
- `forbidden-patterns.json` — machine-verifiable anti-patterns;
- `quality-gates.json` — current route/interaction/pagination/evidence tests required per surface;
- `uiux-scorecard.json` — static compliance threshold;
- `ui-lessons.json` — evidence-backed reusable UI lessons.

Before claiming a UI task complete run:

```bash
python3 scripts/foodex-uiux-audit.py --base <base-sha> --head <head-sha> --report artifacts/foodex-uiux-audit.json
```

A static score below the configured threshold or any critical violation is a FAIL. Passing this script still does **not** replace the runtime evidence gate.

## 15. Context authority and contract planner

Before Golden Page selection, read `.ai/skills/ui-context-authority.md`.

Generate a concrete UI contract when starting a page:

```bash
python3 scripts/foodex-ui-contract-plan.py --surface <surface> --archetype <archetype> [--function-id <id>] [--route <route>]
```

The output binds visual composition to route authority, Store/channel/tenant context, currency/precision authority, asset policy, lifecycle expectations, Golden Page candidates and the exact tests/evidence for that surface.

Do not start page code while those inputs are materially unknown.

## 16. Structural parity and exact-diff evidence

Before merge for UI work:

```bash
python3 scripts/foodex-ui-structure-audit.py --base <base> --head <head> --report artifacts/foodex-ui-structure-audit.json
python3 scripts/foodex-ui-pr-plan.py --base <base> --head <head> --strict --output artifacts/foodex-ui-pr-evidence-plan.json
```

Read:
- `.ai/skills/ui-structural-parity.md`
- `.ai/skills/ui-pr-evidence-plan.md`
- `.ai/uiux/visual-evidence-matrix.json`

New screens must satisfy the surface structural signature and be added to the owning runtime screenshot inventory. Applicable Web/Mobile visual evidence jobs are part of Required CI.

## 17. Business journey learning and short-prompt routing

For short owner prompts, run the production-backed intent router before visual design:

```bash
python3 scripts/foodex-ui-intent-plan.py --intent "<owner request>" [--surface <surface>] [--strict]
```

Read:
- `.ai/skills/ui-business-journeys.md`
- `.ai/skills/ui-intent-routing.md`
- `.ai/uiux/business-casebook.json`
- `.ai/uiux/business-recipes.json`

The selected business case supplies real production sources, tests, state/action invariants and a reusable recipe. Explicit Issue/route authority always wins. A weak prompt must remain unresolved rather than creating invented business behavior.


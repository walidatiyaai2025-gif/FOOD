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

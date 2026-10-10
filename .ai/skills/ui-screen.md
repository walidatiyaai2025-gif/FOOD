# Skill: Build or Change a FOODEX Page/Screen

This is the default entry skill whenever the request is to build, redesign, split, or materially change a UI page.

## Mandatory first step

Read:
1. `.ai/skills/foodex-uiux.md`
2. `.ai/skills/ui-pattern-library.md`
3. `.ai/skills/page-patterns.md`
4. `.ai/skills/ui-route-authority.md` and `.ai/skills/ui-accessibility.md`.
5. exactly one or more surface skills:
   - `.ai/skills/dashboard-uiux.md`
   - `.ai/skills/customer-uiux.md`
   - `.ai/skills/driver-uiux.md`
   - `.ai/skills/van-uiux.md`
6. the authoritative contract/files named by that skill.
7. `.ai/skills/ui-interaction-safety.md` when the page mutates state; `.ai/skills/ui-performance.md` for lists/search/live/high-growth surfaces.
8. `.ai/skills/uiux-audit.md` and `.ai/skills/uiux-evidence.md` are mandatory after implementation before completion.

Do not start markup/widget code before this pass.

## Execution sequence

### 1. Lock route authority and real page context
- read `docs/execution/UI_ROUTE_AUTHORITY.json`;
- canonical route/renderer;
- normal navigation owner;
- nearby production page;
- shared shell/theme/components;
- backend/API/data source;
- permissions;
- owning Issue/mission requirement.

### 2. Compose from existing primitives
Reuse the surface's production shell, tokens, components, state views, buttons, grids/lists and navigation. The worker must inspect the exact shared source named in `ui-pattern-library.md` before creating a replacement primitive.

A new page should look like it has always belonged to FOODEX.

### 3. Apply business UX
- business labels, never routine raw IDs/keys/JSON;
- Lookup/Enum/Builder where appropriate;
- one clear primary action;
- exact-record View/Edit/Manage;
- ellipsis overflow for multiple row actions;
- compact filters;
- explicit loading/empty/error/stale/offline states;
- no fake-live/fake-data fallback.

### 4. Apply interaction safety and scale
For mutations, enforce busy locking, duplicate-submit protection, retry/idempotency evaluation and authoritative reconciliation. For high-growth/live data, enforce pagination/request control/timer lifecycle.

### 5. Apply accessibility
Preserve focus/ARIA/semantic labels/touch targets and non-color-only status meaning.

### 6. Apply localization and direction
Build AR/RTL and EN/LTR together. Localize raw status/state/channel/role/payment/unit values.

### 7. Apply responsive contract
Use the current surface's real width targets and density rules. Do not make desktop a stretched mobile card stack or mobile a squeezed desktop table.

### 8. Verify real behavior
Test the actual route/screen, not only static source.

For visual/interaction requirements, provide the runtime evidence required by the owning Issue/matrix.

## Completion rule

A page is not complete merely because:
- it renders;
- a route exists;
- CI is green;
- a screenshot exists.

It is complete when it follows the current FOODEX surface design system, is normally reachable, binds authoritative data, handles its real states, works in AR/EN and responsive targets, and passes the relevant interaction/runtime evidence gate.

## Mandatory reference lock before implementation

Before writing any Blade/Flutter UI, name the exact reference pattern being reused.

Minimum internal decision:
- **Surface:** Dashboard / Customer / Driver / Van
- **Archetype:** list-management / detail-manage / create-edit / dashboard-KPI / commerce / active-journey / field-operation
- **Production source:** exact theme/component/shell file
- **Nearest reference:** current route/screen or `SCREEN_MANIFEST.json` entry
- **Shared primitives:** exact existing classes/widgets that will compose the page

Then read `.ai/skills/page-patterns.md`.

If the task does not provide design details, this reference lock is the design specification. Do not ask the owner to restate FOODEX spacing/colors/table/action rules.

## Default page construction rule

For an ordinary page request, build in this order:

1. existing application shell/navigation;
2. compact page header/title;
3. sibling tabs only when the domain already uses them;
4. compact filters/search;
5. main data surface;
6. exact-record action pattern;
7. pagination/continuation when applicable;
8. loading/empty/error/stale/offline states;
9. focused modal/drawer/detail sheet for record actions;
10. AR/EN + RTL/LTR + narrow/wide verification.

Do not start by creating a new Card/Scaffold/Table/Button style.


## Mandatory post-build audit

After the page is implemented:

1. run the relevant feature/widget/contract tests;
2. run `.ai/skills/uiux-audit.md` against the actual page;
3. verify runtime evidence using `.ai/skills/uiux-evidence.md`;
4. check that the owning screenshot/visual workflow path filters include the new/changed screen;
5. fix every applicable FAIL/UNKNOWN on the same branch;
6. only then report the page complete.

The worker must not use "not covered by CI" as acceptance when the reason is an incomplete workflow path trigger.

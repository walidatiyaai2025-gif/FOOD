# Skill: Build or Change a FOODEX Page/Screen

This is the default entry skill whenever the request is to build, redesign, split, or materially change a UI page.

## Mandatory first step

Read:
1. `.ai/skills/foodex-uiux.md`
2. exactly one or more surface skills:
   - `.ai/skills/dashboard-uiux.md`
   - `.ai/skills/customer-uiux.md`
   - `.ai/skills/driver-uiux.md`
   - `.ai/skills/van-uiux.md`
3. the authoritative contract/files named by that skill.

Do not start markup/widget code before this pass.

## Execution sequence

### 1. Find the real page context
- canonical route;
- normal navigation owner;
- nearby production page;
- shared shell/theme/components;
- backend/API/data source;
- permissions;
- owning Issue/mission requirement.

### 2. Compose from existing primitives
Reuse the surface's production shell, tokens, components, state views, buttons, grids/lists and navigation.

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

### 4. Apply localization and direction
Build AR/RTL and EN/LTR together. Localize raw status/state/channel/role/payment/unit values.

### 5. Apply responsive contract
Use the current surface's real width targets and density rules. Do not make desktop a stretched mobile card stack or mobile a squeezed desktop table.

### 6. Verify real behavior
Test the actual route/screen, not only static source.

For visual/interaction requirements, provide the runtime evidence required by the owning Issue/matrix.

## Completion rule

A page is not complete merely because:
- it renders;
- a route exists;
- CI is green;
- a screenshot exists.

It is complete when it follows the current FOODEX surface design system, is normally reachable, binds authoritative data, handles its real states, works in AR/EN and responsive targets, and passes the relevant interaction/runtime evidence gate.

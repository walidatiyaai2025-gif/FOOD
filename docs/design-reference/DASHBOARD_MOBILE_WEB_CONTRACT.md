# FOODEX Dashboard Mobile Web Design Contract

Status: **OWNER APPROVED / MANDATORY**  
Scope: Laravel Management Dashboard on mobile browsers  
Applies to: **every existing Dashboard page and every future Dashboard page**  
Primary authority: `docs/design-reference/DASHBOARD_UI_UX_CONTRACT.md`

## 1. Product definition

FOODEX Dashboard on a phone is a **first-class responsive web application**.

It is not:
- a separate Flutter/mobile application;
- a scaled-down screenshot of desktop;
- a desktop table forced inside a narrow viewport;
- a reduced-function version of the Dashboard.

The same route, permissions, authoritative data, mutations and business capabilities remain available. Only the interaction composition changes for the viewport.

## 2. Required viewport targets

Every Dashboard change must remain usable at:
- 320 px;
- 360 px;
- 390 px;
- 412 px;
- 430 px;
- 768 px tablet boundary;
- 1024 px compact desktop boundary;
- 1280 px+ desktop.

Mobile Web is defined as **below 768 px**. Tablet/compact behavior may begin below 1024 px.

## 3. Hard rules

The following are functional acceptance requirements:

1. No page-level horizontal overflow on mobile.
2. No pinch-zoom requirement to read or operate routine business UI.
3. No desktop sidebar stacked above the page.
4. No desktop data grid squeezed to unreadable columns.
5. No missing action simply because the viewport is mobile.
6. No control smaller than the shared 44 px touch target for primary interactive targets.
7. Mobile form controls use a readable size and must not trigger browser auto-zoom; text inputs/selects/textarea target at least 16 px.
8. Arabic/RTL and English/LTR are equally supported.
9. Status, totals, prices, references and action availability use the same authoritative backend state as desktop.
10. Existing and future pages compose shared Mobile Web primitives before adding page-local responsive CSS.

## 4. Mobile page anatomy

On mobile, a normal Dashboard page is composed in this order:

1. mobile navigation launcher;
2. compact page title and short subtitle inside the page toolbar;
3. primary action;
4. horizontally scrollable sibling tabs when present;
5. compact search/filter surface;
6. KPI/status summary when relevant;
7. primary operational content;
8. exact-record actions;
9. pagination/continuation;
10. secondary analytics or low-priority detail.

Desktop visual order does not have to be preserved when it harms mobile task priority.

## 5. Navigation contract

### Desktop
The canonical business-domain sidebar remains authoritative.

### Mobile
The same sidebar becomes an **off-canvas drawer**:
- opened from a persistent 44 px launcher;
- covers the content rather than consuming document height;
- closes by the drawer toggle, backdrop, Escape, or selecting a navigation destination;
- traps no permanent page width;
- preserves the same authorization-filtered navigation tree;
- preserves search inside the navigation;
- opens from the logical start edge: right in RTL, left in LTR.

A mobile page must never render the complete desktop navigation as a large block above the working content.

## 6. Page header and actions

On mobile:
- title/subtitle are compact and data-first;
- title must wrap naturally without forcing width overflow;
- primary actions remain visible;
- multiple header actions use a horizontal action rail or compact overflow instead of a tall wrapped button wall;
- notification/account controls remain touch-safe.

The platform chrome is the single shared `admin._global-header`. It owns global search, notifications and account access. Page title/subtitle/actions render below it inside `.foodex-page-toolbar` with `.foodex-header-actions`; a page must not introduce a second platform header.

## 7. Tabs

Sibling tabs:
- remain one row;
- use horizontal scrolling on narrow screens;
- never wrap into a multi-row navigation wall;
- preserve active state and route/query context.

Use `.foodex-tabs` / `.foodex-tab`.

## 8. Filters and search

Mobile filters must be task-oriented.

Preferred order:
- primary search remains directly available;
- common one/two-value filters may stay inline when they fit;
- advanced/many filters should use a compact filter trigger and focused drawer/sheet;
- date ranges should remain compact and avoid unnecessary vertical repetition;
- Apply/Search remains reachable without horizontal page overflow.

Do not solve mobile filtering by shrinking every desktop control.

## 9. Data-grid contract

Operational tables must not become squeezed desktop tables.

### Default mobile behavior
Canonical `.foodex-table` and `.foodex-ops-grid` tables are enhanced into **record cards** below 768 px:
- each desktop row becomes one mobile record card;
- each visible cell carries its business column label;
- values remain selectable/copyable;
- status badges and exact-record actions remain intact;
- row actions continue to use the compact ellipsis menu;
- the card remains in the normal document flow.

### Explicit scroll exception
Use `data-foodex-mobile-table="scroll"` only when the data is genuinely matrix/analytic and a card representation would destroy meaning.

The scroll exception must be contained inside its own table wrapper. It must never cause page-level horizontal overflow.

### Explicit card opt-in/opt-out
The shared runtime may set `data-foodex-mobile-table-ready="1"`. Page code must not duplicate the same conversion logic.

## 10. Forms

Below 768 px:
- form layouts collapse to one primary column unless a tightly related pair safely fits;
- labels remain visible;
- inputs/selects/textarea use full available width;
- controls maintain 44 px minimum interaction geometry;
- text controls target 16 px minimum font size;
- validation stays next to the field;
- long create/edit flows prefer focused modal/drawer/wizard sections rather than permanent giant forms;
- save/confirm actions remain obvious and reachable.

No user-facing internal IDs, raw keys or JSON are introduced as a mobile shortcut.

## 11. Modals, drawers and focused workflows

Desktop modal behavior remains canonical.

On mobile:
- dialogs use nearly/full viewport width;
- large operational dialogs become bottom-sheet/full-height surfaces as appropriate;
- internal content scrolls; the underlying page does not;
- close control remains at least 44 px;
- Escape still closes when supported by the browser/keyboard;
- direct-record context is preserved.

## 12. KPI, card and dashboard composition

Mobile analytics must prioritize decisions over symmetry.

Rules:
- KPI grids may become 2×N when width safely permits, otherwise one column;
- critical KPIs appear before secondary charts;
- charts use the shared FOODEX visualization primitives;
- legends may stack vertically;
- labels may reduce density, but business meaning must not disappear;
- maps use the available width and controls must not cover essential map content;
- decorative whitespace must not dominate the viewport.

## 13. Actions

Mobile does not remove business capability.

- One clear primary action is preferred.
- Multiple row actions remain in the ellipsis action menu.
- Destructive actions still require the existing confirmation/safety contract.
- Sticky bottom actions may be used for long focused workflows through the shared primitive.
- Buttons never rely on hover to reveal their only meaning.

## 14. Pagination

Pagination must:
- fit narrow screens;
- center/condense page controls when needed;
- preserve server-side query/filter state;
- avoid page-level horizontal overflow;
- retain a readable result summary when useful.

## 15. Mobile Web shared primitives

The canonical Dashboard shared layer owns mobile behavior.

Required shared primitives include:
- `.foodex-mobile-nav-launcher`
- `.foodex-mobile-nav-backdrop`
- `.foodex-mobile-action-rail`
- `.foodex-mobile-filter-sheet` when a page needs advanced filters
- `.foodex-mobile-sticky-actions` for long focused workflows
- automatic mobile-card enhancement for `.foodex-table` and `.foodex-ops-grid`

A page must not create a second navigation drawer, card-table engine or mobile shell unless the shared primitive is deliberately being replaced.

## 16. RTL/LTR

Arabic mobile web:
- drawer opens from the right;
- flow and action ordering remain RTL;
- directional icons are mirrored where meaning requires it;
- numbers/currency may remain tabular but must not corrupt RTL layout.

English mobile web:
- drawer opens from the left;
- equivalent information hierarchy and actions remain available.

## 17. Accessibility

Mobile Web keeps the Dashboard accessibility contract:
- 44 px interaction targets;
- visible focus;
- semantic labels/ARIA;
- keyboard Escape for dismissible overlays where supported;
- backdrop dismissal where safe;
- no status communicated by color alone;
- reduced-motion preferences respected where animation exists.

## 18. Performance

Mobile web is expected to work on constrained networks/devices.

Prefer:
- server pagination;
- debounced/controlled search;
- lazy/non-blocking secondary analytics;
- appropriately sized images;
- bounded live polling;
- no duplicate fetch loops;
- no large hidden desktop duplicate DOM solely for mobile presentation.

Responsive adaptation should reuse the same semantic DOM where practical.

## 19. Existing-page migration rule

This contract applies retroactively.

Existing pages inherit the shared foundation immediately. When an existing page is touched:
1. remove page-local behavior that conflicts with this contract;
2. adopt shared table/navigation/form/dialog primitives;
3. verify the real mobile route;
4. fix overflow or unusable composition found in the touched business journey.

Legacy status is not an exemption.

## 20. Future-page rule

A new Dashboard page is incomplete unless:
- it uses the canonical Dashboard shell;
- it inherits the Mobile Web shared layer;
- it remains usable at 320/360/390/412/430 px;
- it has no page-level horizontal overflow;
- its operational table strategy is explicitly card-default or justified scroll-exception;
- AR/RTL and EN/LTR are both valid;
- desktop business capability is not silently removed on mobile.

## 21. Definition of Done

A Dashboard page fails Mobile Web acceptance if any of the following is true:
- desktop navigation occupies the top of the mobile page;
- page-level horizontal scrolling is required;
- a routine operational table can only be understood by sideways scrolling;
- a primary action is clipped/off-screen;
- text controls trigger browser zoom due to undersized typography;
- a desktop action/function is absent on mobile without an explicit product decision;
- modal content cannot be completed on a 320 px viewport;
- Arabic or English breaks direction/layout;
- the implementation duplicates shared Mobile Web behavior page-locally.

Passing desktop CI alone is not proof. Runtime responsive evidence is required where the page materially changes by width.

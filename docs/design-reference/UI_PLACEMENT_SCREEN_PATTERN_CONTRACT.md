# FOODEX Adaptive Placement & Screen Pattern Contract

Status: **OWNER APPROVED / MANDATORY**  
Scope: business-facing FOODEX UI, with primary enforcement on Dashboard/Admin/business web and the same placement decision applied to cross-surface Customer/Driver/Van work when a feature adds or changes a screen, route, navigation entry, form, workbench or major interaction surface.

This contract makes **where a capability belongs** a deliberate product-architecture decision before code is written. It extends the existing FOODEX UI/UX system; it does not create a second design system or replace route, localization, accessibility, Master Data, responsive or evidence authorities.

## 1. Authority and non-duplication

This contract must be read with, not instead of:

- `AGENTS.md`;
- `docs/design-reference/DASHBOARD_UI_UX_CONTRACT.md`;
- `docs/design-reference/DASHBOARD_MOBILE_WEB_CONTRACT.md`;
- `.ai/skills/ui-route-authority.md`;
- `.ai/skills/ui-accessibility.md`;
- `docs/quality/LOCALIZATION_CONTRACT.md`;
- the current surface-specific design target and evidence matrix.

Existing route authority remains authoritative. Existing localization, accessibility, permissions, Master Data and release rules remain authoritative. When a placement decision selects an existing route, the implementation extends that route instead of duplicating it.

An Issue title describes **intent**, not route authority. A title such as “New X screen” or “Add Y page” does not authorize a new route, Sidebar item or renderer by itself.

## 2. Mandatory placement decision before code

Every new or materially changed business-facing UI capability must resolve this chain before markup/widgets/navigation code:

> **Domain → Entity → Role → User Goal → Workflow → Existing Parent/Route → Placement → Screen Pattern → Responsive Composition**

The owning Issue/PR must make the following metadata explicit:

- `domain`
- `entity`
- `role`
- `user_goal`
- `workflow_type`
- `frequency`
- `data_density`
- `existing_parent`
- `placement_type`
- `pattern`
- `navigation_impact`
- `desktop`
- `tablet`
- `mobile`
- `rtl_ltr`
- `permissions`
- `states`

Also identify the current canonical route/renderer when one exists.

Unknown placement metadata is a planning defect. Do not fill unknowns by copying the Issue title into a route name.

## 3. Mandatory placement order

Use this order and stop at the first option that satisfies the user goal without damaging clarity, permissions, discoverability or workflow integrity:

1. **Reuse** — use the existing canonical screen/workflow unchanged except for the smallest necessary capability.
2. **Extend** — add the capability to the existing record, form, grid, details surface or action model.
3. **Compose** — combine existing primitives into a tab, drawer, modal, workbench section, dashboard region or focused workflow inside the existing domain.
4. **New Screen** — create a new canonical route/screen only when the first three options are insufficient.

A new screen requires an explicit justification recording why Reuse, Extend and Compose are insufficient. “The Issue asked for a page” is not valid justification.

## 4. Placement types

Choose the smallest appropriate placement:

- **inline** — compact information/action belongs directly beside the data it affects;
- **action** — a command launched from a record, row, header or contextual menu;
- **modal** — short focused confirmation/edit/create task that should preserve page context;
- **drawer/sheet** — contextual detail or focused workflow that benefits from more room without abandoning the parent surface;
- **tab** — a sibling function of the same entity/workspace with shared context and authority;
- **workbench** — high-frequency multi-record operational work for a role/team;
- **dashboard region** — monitoring/KPI/exception summary where the primary goal is situational awareness;
- **standalone page** — a durable destination with independent user goal, route authority and business-domain ownership.

Placement must follow the user goal and workflow, not implementation convenience.

## 5. Canonical screen-pattern catalog

Every screen or major composed surface must map to one primary pattern:

1. **Executive Dashboard** — decision/KPI overview with drill-down.
2. **Operational Workbench** — high-frequency queues, actions and exceptions.
3. **Data Explorer / Grid** — search/filter/sort/compare many records.
4. **Record 360 / Details** — one entity, its state, relationships, history and actions.
5. **Create/Edit Form** — focused data capture or maintenance.
6. **Wizard / Multi-step Flow** — ordered dependent steps with progress and validation.
7. **Settings / Master Data** — controlled configuration and authoritative lookup management.
8. **Monitoring / Exceptions** — live/near-live health, alerts, failures and recovery.
9. **Action Center / Queue** — pending approvals/tasks/actions across a bounded domain.
10. **Analytics / BI** — analysis, trends, segmentation and drill-down.

Secondary patterns may exist inside a screen, but one primary pattern owns its information hierarchy.

## 6. Tabs, view switches and wizards

Use **tabs** only for sibling functions of the same entity/workspace when each sibling is independently revisitable and shares the same route/domain context.

Use a **view switch** when the same dataset is shown differently, for example Grid / Cards / Map. A view switch is not a tab and must not create duplicate business functions.

Use a **wizard/stepper** when the user must complete ordered dependent steps. Do not represent sequential work as unrelated tabs.

Use an **accordion/progressive disclosure** for optional or advanced sections inside one task; do not promote rare fields to permanent top-level navigation.

## 7. Navigation budget and standalone-route justification

The Sidebar is a business-domain navigator, not a feature dump.

Before adding a Sidebar entry, prove all of the following:

1. the destination belongs to a stable business domain;
2. the destination has a durable independent user goal;
3. its owning role needs normal repeated navigation to it;
4. it cannot live correctly as an existing record/tab/action/drawer/workbench region;
5. route authority and permissions are explicit;
6. the navigation impact does not duplicate or fragment an existing domain.

A standalone route does **not** automatically require a Sidebar item. Low-frequency destinations may be reached from a parent screen, exact-record action, Admin Hub, Settings/Master Data hub or contextual workflow.

Top-level Sidebar additions require explicit business-domain ownership. Project names, epics, implementation phases and Issue titles are never navigation domains.

## 8. Form information hierarchy and field classification

Before implementing a business form:

1. group fields into semantic task sections;
2. order sections by user task and data dependency;
3. classify every field as:
   - **Lookup**
   - **Enum**
   - **Builder**
   - **Free input**
   - **Advanced technical input**
4. keep tightly related fields together;
5. use progressive disclosure for rare/advanced data;
6. place validation beside the affected field;
7. keep the complete task usable on mobile.

Raw database IDs, keys, internal codes, GeoJSON or JSON are never the routine default merely because the backend accepts them. Managed values must come from authoritative Master Data.

## 9. Semantic icon and state vocabulary

Use one semantic meaning for the same concept across modules. Prefer existing FOODEX icon primitives before introducing new icons.

Baseline semantics:

- create/add → plus/add;
- edit → pencil/edit;
- view/details → eye/details;
- more row actions → ellipsis;
- search → magnifier;
- filter → funnel/filter;
- refresh/retry → refresh;
- download/export → download;
- upload/import → upload;
- approve/success → check;
- warning/attention → warning;
- error/destructive → error/trash only when the action/state is actually destructive;
- settings → settings/gear;
- history/audit → history/clock;
- location/map → location/map marker.

Ambiguous or destructive icon actions require a text label, tooltip or accessible name. Color may reinforce state but never carry the only meaning. State labels must remain localized and accessible.

## 10. Responsive composition is part of placement

The same route, authority, permissions, data and business capability remain available across widths; composition may change.

### Desktop

Use the domain shell, appropriate density, multi-column layouts where useful, full workbench/grid capability and clear primary/secondary action hierarchy.

### Tablet

Preserve the same workflow while reducing simultaneous columns, collapsing secondary panels and keeping important actions/filters reachable. Tablet is not an afterthought between desktop and phone.

### Mobile web

Follow `DASHBOARD_MOBILE_WEB_CONTRACT.md`: off-canvas domain navigation, compact headers/actions/tabs, task-oriented filters, operational grids as record cards by default, focused single-column/fitted-pair forms, touch-safe drawers/modals and no page-level horizontal overflow.

For Dashboard changes, plan and evidence the contract widths: 320 / 360 / 390 / 412 / 430 px, 768 px tablet, 1024 px compact desktop and 1280 px+ desktop as applicable.

## 11. Locale, permissions and state composition

Every placement decision must define:

- Arabic/RTL and English/LTR behavior;
- role/permission/entitlement behavior;
- loading state;
- empty/no-results state;
- validation/error state;
- disabled/unavailable state;
- stale/offline state when the data can become stale;
- permission-denied/hidden behavior where relevant.

A layout that works only in one locale, one permission level or one loaded-success state is incomplete.

## 12. Required Issue evidence

Before implementation, a business-facing UI Issue must contain:

- the mandatory placement metadata from Section 2;
- the selected **Reuse / Extend / Compose / New Screen** decision;
- canonical route/parent and selected screen pattern;
- new-screen justification when applicable;
- navigation impact and domain owner when Sidebar/standalone navigation changes;
- planned Desktop, Tablet and Mobile composition;
- planned AR/RTL and EN/LTR behavior;
- permissions and required runtime states.

If those fields are missing, the worker resolves them before code. It must not infer a route from the Issue title.

## 13. Required PR evidence

A UI PR must record, for the exact head:

- final placement decision and canonical route/parent;
- screen pattern and any new-screen/Sidebar justification;
- Desktop evidence;
- Tablet evidence;
- Mobile evidence;
- Arabic/RTL evidence;
- English/LTR evidence;
- permission/entitlement evidence where changed;
- loading/empty/error/stale/disabled evidence where applicable;
- applicable structural, localization, accessibility and visual/runtime gates.

Evidence may be automated artifacts, runtime captures or test records according to the existing UI evidence contracts. A generic green unit-test job does not replace required responsive/runtime evidence.

## 14. Future-feature enforcement

For every future business-facing UI capability:

1. read this contract before code;
2. complete the placement metadata;
3. resolve **Reuse → Extend → Compose → New Screen**;
4. select a canonical pattern;
5. preserve route/localization/accessibility/Master Data authorities;
6. implement with the current shared FOODEX primitives;
7. produce Desktop/Tablet/Mobile + AR/EN evidence;
8. reject a new route/Sidebar item that lacks the required justification.

The repository UI skills and templates consume this contract. Workers must update stale repository-native guidance in the same PR when it contradicts this contract.

## 15. Definition of Done

Placement/pattern acceptance fails when any of the following is true:

- the Issue title was treated as the route specification;
- an existing canonical route could have been reused/extended/composed but a duplicate screen was created;
- a new screen lacks explicit Reuse/Extend/Compose insufficiency justification;
- a Sidebar item lacks stable business-domain ownership;
- tabs are used as a view switch or sequential wizard;
- a form exposes routine raw technical identifiers instead of the correct field type;
- icon/state meaning is inconsistent or color-only;
- Desktop, Tablet or Mobile composition is unspecified or unusable;
- AR/RTL or EN/LTR evidence is missing;
- permissions or required runtime states are undefined;
- existing route, localization, accessibility, Master Data or evidence authority was duplicated or weakened.

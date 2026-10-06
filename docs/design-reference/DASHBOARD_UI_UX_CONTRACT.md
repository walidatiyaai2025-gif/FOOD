# FOODEX Dashboard UI/UX & Master-Data Contract

This document is a **mandatory project contract** for every worker that creates or changes Dashboard UI, Admin navigation, mobile-application administration, or any business-facing form.

It applies to the **entire FOODEX Dashboard**, not only Van features.

A worker must treat these rules as part of the Definition of Done. If a new feature conflicts with this contract, the implementation must be redesigned or the repository owner must explicitly approve an exception.

---

## 1. Business-domain navigation

The main Sidebar is for business domains, not for project/epic names.

Examples:

- Vans: Van registry, Van details, Van assignments, Van status/capacity/activity.
- Drivers: driver-specific operations.
- Customers: customer-specific operations, visits and service context.
- Promotions / Marketing: Flash Offers and promotional features.
- Finance / Invoices: invoices, collections, reconciliation and finance actions.
- Operations / Routing: visits, routes and operating workflows.
- Geography / Territories: service areas, geographic hierarchy and address quality.
- Sales Control: selling units, quotas, restrictions and channel rules.

Do not create one oversized "Van Operations" container that hides unrelated domains inside it.

---

## 2. Page-level tabs

Related functions inside one module should be exposed as **horizontal tabs in the page header/container**.

The Sidebar chooses the domain. Tabs choose the function inside that domain.

Selecting a tab should update the workspace below it while preserving user context whenever practical.

Use business-readable labels. Do not expose internal technical terminology as navigation.

---

## 3. Add / Create actions

Every add/create flow must start from a clear primary action button such as:

- Add Driver
- Add Van
- Add Customer
- Create Flash Offer
- Add Territory

The button must open a Modal, Drawer, or Wizard that completes the operation without forcing the user through unrelated pages.

After save, return the user to the same working context and refresh the affected grid/workspace.

Do not leave large create forms permanently open when creation is an event-based action.

---

## 4. Direct record actions

Actions must target the actual record.

Example: "Manage Order" from Finance/Invoices must open that exact order directly in its management Modal/Drawer/detail context. It must not send the user to the general Orders page and force another search.

Apply the same principle to Orders, Customers, Drivers, Vans, Invoices, Offers, Territories, Users and other entities.

---

## 5. Button visual contract

Dashboard action buttons use the FOODEX button component:

- FOODEX green background;
- white text;
- consistent FOODEX spacing, radius and interaction states.

Do not use white action buttons.

Do not expose default Bootstrap button styling such as raw `btn-primary`, `btn-secondary`, `btn-light` visual treatment.

Shared components/design tokens are authoritative.

---

## 6. Grid contract

Dashboard data grids should follow the Orders-grid interaction model.

Each row should expose useful business data directly.

For long/complex values, show a small readable sample/summary in the row rather than hiding all useful information behind a detail view.

Entity relationships must show meaningful names/codes intended for humans, never raw database IDs.

Where row-level actions exist, expose the three most important actions clearly on the row, using FOODEX green/white action buttons. Additional low-frequency actions may be grouped in a secondary menu if needed.

Status must use readable FOODEX badges rather than raw values.

---

## 7. Master Data / Lookup rule — no user-facing IDs, keys or codes

This is a hard rule.

If a field represents an entity or value managed elsewhere in FOODEX, the business user must **not** type its database ID, internal key, raw code, or JSON representation.

Examples include:

- Van
- Driver / Representative
- Customer
- Store
- Warehouse
- Territory / Region
- Route
- Order
- Product
- Selling Unit
- User
- Customer Group
- any other managed Master Data entity

The UI must provide a clear Lookup/Search/Select control.

### Lookup source rule

Every lookup must have an authoritative source.

If the lookup value is managed by a FOODEX page/module, the lookup must read directly from that same authoritative data source.

Do not hard-code dropdown values that are supposed to be managed Master Data.

Example:

- A Territory field must select from territories created in Territory Management.
- A Warehouse field must select from Warehouse Management.
- A Driver field must select from Driver Management.
- A Product field must select from Product Management.

### Lookup UX

Where useful, show an explicit search/lookup affordance beside the field.

The user sees meaningful labels such as name, code intended for business use, status, branch or context. Internal IDs remain hidden and are stored only behind the UI.

If the user has permission and the workflow benefits from it, a lookup may offer an "Add new" action that opens the authoritative create workflow without losing the current context.

### Classification before implementation

For every business-facing field, the worker must classify it as one of:

A. Lookup / Master Data selector  
B. Enum / finite business selector  
C. Structured Builder  
D. Legitimate free text/number/date input  
E. Advanced technical input restricted to an appropriate admin context

Raw IDs, keys and JSON must never be used merely because they are easy for the backend.

---

## 8. Geography / Territories — map first

Business users must not be expected to enter GeoJSON, territory keys or geographic codes.

Creating/editing a service area should be map-first:

1. click Add Territory / Add Geographic Area;
2. open a large map/full-screen map workflow;
3. choose any required parent/geographic context through lookups;
4. draw the polygon directly on the map;
5. edit/move/delete points and use Undo/Clear;
6. see existing/adjacent territories when useful;
7. validate the polygon before save.

Raw GeoJSON may exist only as an advanced/internal capability for appropriate privileged users.

---

## 9. Admin Hub

The Sidebar item "Administration" should be a single main entry.

Opening it should show an **Admin Hub page with icon/cards**, not a long nested list of links.

Each icon/card opens one administrative domain, for example:

- Applications
- Users & Permissions
- System Settings
- Notifications
- Publishing / Store readiness
- Integrations

The Admin Hub itself must follow the same FOODEX page-header, tabs, grid, button and modal standards.

---

## 10. Three official applications

FOODEX now has three first-class applications:

1. Customer
2. Driver
3. Van

Application administration must show all three.

Any application-level capability implemented for Customer and Driver must be evaluated for Van in the same implementation plan.

Examples:

- application identity;
- version/build display;
- API/environment configuration;
- feature flags;
- notifications/Firebase where applicable;
- Arabic/English and RTL/LTR;
- privacy/terms/support;
- session/account lifecycle;
- publishing/readiness settings where applicable;
- CI, visual evidence and runtime health.

If a capability genuinely does not apply to Van, the exception must be documented explicitly. "Van was not considered" is not acceptable.

---

## 11. FOODEX shared design system

Workers must prefer shared FOODEX components and design tokens over page-local UI inventions.

For Laravel Admin surfaces, use the shared FOODEX admin shell/components where applicable.

Do not build isolated Bootstrap-looking pages, one-off flat commercial pages, or duplicate controls when an authoritative shared component already exists.

Arabic/English and responsive behavior are part of acceptance, not optional polish.

---

## 12. Worker checklist for every new page/feature

Before implementation, answer:

- Which Sidebar business domain owns this feature?
- Which page-level tab/function owns it?
- What is the primary Add/Create action?
- Does Add/Create use a Modal/Drawer/Wizard?
- Do Manage/Edit/View actions open the exact record?
- Does the grid follow the Orders-grid contract?
- Are action buttons FOODEX green with white text?
- Which fields are Lookups? What is the authoritative Master page/source for each?
- Are any raw IDs/keys/codes/JSON exposed to a business user? If yes, redesign.
- Does the feature affect Customer, Driver or Van application administration?
- If it is an app-level capability, was parity across all three applications evaluated?
- Is geography map-first when geographic selection/drawing is involved?
- Are Arabic/English and responsive states covered?
- Is the implementation using shared FOODEX components rather than default Bootstrap/page-local duplicates?

A feature is incomplete until these questions are resolved.

---

## 13. Review rule

During review, a worker must treat violations of this contract as functional UX defects, not cosmetic suggestions.

New pages/features should not be declared complete if they:

- require manual internal IDs/codes/keys;
- duplicate Master Data inside hard-coded dropdowns;
- send direct actions to generic listing pages;
- expose permanent raw JSON for routine business work;
- use default Bootstrap visual actions;
- bypass the FOODEX shared design language;
- omit Van from application-level parity without a documented reason.

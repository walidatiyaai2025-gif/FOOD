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

Where row-level actions exist, use **one compact FOODEX-green three-dots (ellipsis) action button** in the Actions column. The ellipsis menu contains the actions available for that record. Do not spread multiple action buttons across the row unless the repository owner explicitly requires a dedicated primary action.

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


---

## 14. Compact mobile layout contract - Customer / Driver / Van

This section is mandatory for all three FOODEX mobile applications:

1. Customer
2. Driver
3. Van

The core objective is to maximize usable data area and avoid oversized decorative UI.

### 14.1 Compact title/header footprint

- A page title and its small subtitle must be extremely compact.
- The combined title + subtitle block should target **no more than approximately 1% of the usable page area**.
- Do not use oversized headers/subheaders that consume roughly 20% or more of the viewport.
- Use a readable, balanced font size: not tiny, not oversized.
- Minimize top/bottom padding around page titles.
- Prefer the actual working data area over decorative vertical spacing.

### 14.2 Use the full available screen

- Customer, Driver and Van screens must use the available viewport width and height efficiently.
- Avoid unnecessary large margins, fixed-height empty areas, oversized cards, or narrow centered content when the screen can safely show more data.
- Content should feel full-width and data-first while preserving safe areas and basic touch accessibility.
- The goal is a comfortable information-dense application, not a page where one card/row dominates the screen.

### 14.3 One-line filter bars

Filters must be compact.

If a workflow contains Start and End values (for example Start Date and End Date), they must appear **side by side on the same line**, with the related action button on that same line.

Example:

`Start | End | Apply/Search`

Rules:

- Do not place Start on one row, End on another row, and the button below them.
- The filter bar should not wrap to multiple lines.
- On narrow screens, use compact controls, a date-range control, or horizontal scrolling rather than vertical wrapping.
- Apply this to invoices, orders, reports, date-range searches and any equivalent filtering surface across all three apps.

### 14.4 Order number must never wrap

- An Order Number / Order Reference must always render on **one line** everywhere in Customer, Driver and Van.
- Use `nowrap`/equivalent behavior.
- Do not split an order number across two lines.
- If space is constrained, preserve the full identifier through compact layout, horizontal room, or controlled truncation with a direct way to reveal/copy the complete value. Never make the identifier visually ambiguous.

### 14.5 Compact list/grid rows

- Mobile grids/lists must use compact rows/items.
- A normal row must not consume most of the viewport height.
- Prefer concise stacked elements inside the row: key identity, status, short supporting data, and compact metadata.
- Avoid oversized cards with excessive padding.
- Show enough rows/items on one screen to let the user scan data comfortably.
- Long values should use concise samples/secondary text where appropriate without hiding critical identifiers.

### 14.6 Row actions use one ellipsis menu

When a record has available actions:

- show one compact **three-dots (ellipsis) button** on the row;
- the button uses FOODEX green with a white icon/dots;
- opening it shows the actions allowed for that record and user permission;
- action labels must be business-readable;
- selecting Manage/Edit/View must open the exact record/action context directly.

Do not place several large action buttons across every mobile row.

### 14.7 Typography density

- Typography must remain readable without becoming visually dominant.
- Titles, labels, values and metadata should use a controlled scale.
- Avoid very large fonts that reduce the visible data area.
- Avoid excessively small fonts that harm readability.
- Use emphasis through weight/hierarchy before increasing font size.

### 14.8 Mobile layout review gate

For every Customer / Driver / Van screen, review must explicitly verify:

- compact title/subtitle footprint;
- no oversized header taking meaningful data space;
- full use of available viewport;
- Start/End/action filters remain on one line;
- Order Number never wraps;
- row/list density is comfortable and more than one normal record can be scanned per screen where data permits;
- row actions use the green ellipsis menu;
- font size is readable and space-efficient;
- Arabic and English layouts preserve the same density and behavior.

A mobile feature is incomplete if it wastes large parts of the viewport on headers, filters, padding or oversized rows.


---

## 15. Remember Me + Biometric unlock contract - Customer / Driver / Van

This capability is mandatory to evaluate and implement consistently across all three FOODEX mobile applications:

1. Customer
2. Driver
3. Van

### 15.1 User experience

After a successful username/password sign-in, the user may opt in to:

- **Remember Me**: keep the authenticated session available on the device according to the product/session policy.
- **Biometric Unlock**: use the device biometric mechanism (fingerprint / face / platform-supported biometric) to reopen the app without entering username and password every time.

The user must be able to enable or disable these options from the login/profile/security experience.

### 15.2 Security model

Biometrics must unlock a securely stored session/credential artifact. The application must **not** store the user's plaintext password for biometric sign-in.

Use platform secure storage / keystore / keychain mechanisms for persistent secrets or refresh/session tokens.

Biometric authentication is device-local confirmation; backend authorization, tenant/store/channel permissions and session validity remain authoritative.

### 15.3 Fallback and invalidation

Require full username/password sign-in again when appropriate, including when:

- the remembered session/token expires or is revoked;
- the user explicitly signs out;
- account/security policy requires reauthentication;
- secure storage cannot be read;
- device biometric enrollment/security state changes in a way that invalidates stored access;
- the backend rejects the stored session.

The login screen must always provide a normal credential fallback.

### 15.4 Logout / account safety

A full Sign Out must clear the remembered authenticated session and any local biometric unlock material associated with that account, unless product policy explicitly distinguishes a safe remembered account identifier from authentication material.

Do not let biometric UI bypass server-side authorization or disabled-account checks.

### 15.5 Three-app parity gate

Any change to login/session/Remember Me/biometric behavior must be reviewed for **Customer + Driver + Van** in the same implementation plan.

If platform support or a business role creates a justified difference, document the exception explicitly.

### 15.6 Acceptance checks

For each of Customer, Driver and Van verify:

- first login with username/password works;
- Remember Me is opt-in and behaves according to session policy;
- biometric opt-in is available after successful authentication;
- biometric unlock opens the authenticated experience without requesting credentials again while the session is valid;
- failed/cancelled biometric returns safely to login/unlock state;
- expired/revoked session falls back to full login;
- Sign Out removes authentication material;
- no plaintext password is persisted;
- Android/iOS behavior is validated where the app supports those platforms;
- Arabic/English labels and accessibility are covered.


---

## 16. Main Dashboard live tracking map - Drivers + Vans

The first/main Dashboard map is a **Live Tracking** map for both Drivers and Vans.

UI wording should be business-readable and generic:

- Arabic: **التتبع الحي**
- English: **Live Tracking**

Do not label the map as Driver-only when it displays both entity types.

### 16.1 Marker types

- A **Driver** is represented by a clear person/driver icon.
- A **Van** is represented by a clear vehicle/van icon.
- The two marker types must be visually distinct at a glance.
- Each marker appears at the entity's latest valid known/live geographic position.
- Clicking/tapping a marker opens the relevant entity context/details directly where supported.

### 16.2 Map usability and truthfulness

- Include a compact legend explaining Driver vs Van markers.
- Preserve FOODEX visual identity.
- Do not expose raw IDs, coordinates, keys or technical map data to business users.
- Where useful, provide compact filters for **Drivers / Vans / Both**.
- Offline/stale/no-location states must be explicit; never present stale coordinates as live.
- The map must prioritize operating visibility over decorative UI and should use the available map area efficiently.

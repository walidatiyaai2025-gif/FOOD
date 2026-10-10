# Skill: FOODEX Dashboard UI/UX

Use for Laravel Management Dashboard/Admin/business-facing web pages.

## 1. Current authoritative implementation sources

Read before editing:
- `backend/resources/views/admin/_brand.blade.php`
- `backend/resources/views/admin/_brand-components.blade.php`
- `backend/resources/views/admin/shell.blade.php`
- `docs/design-reference/DASHBOARD_UI_UX_CONTRACT.md`
- `docs/design-reference/PH05_PREMIUM_DASHBOARD.md` for premium dashboard composition
- `docs/design-reference/FOODEX_VISUALIZATION_CONTRACT.md` for charts
- nearby production page in the same domain

Do not create a standalone shell if the canonical admin shell exists.

## 2. Production brand tokens — current snapshot

Current source: `_brand.blade.php`. Re-read source before coding because source is authoritative.

### Colors
- Green: `#158A3A`
- Green Dark: `#165D2D`
- Green Bright: `#27B658`
- Green Soft: `#EAF7EF`
- Orange: `#EE731C`
- Orange Bright: `#FC8F33`
- Orange Soft: `#FFF1E6`
- Blue: `#4B8CF5`
- Red: `#EF5350`
- Ink: `#172033`
- Muted: `#667085`
- Surface: `#FFFFFF`
- Background: `#F7F9FC`
- Border: `#E6EAF0`

### Typography
- Dashboard font: Tajawal
- weights: 400 / 500 / 700
- xs `.75rem`
- sm `.875rem`
- md `1rem`
- lg `1.125rem`
- xl `1.5rem`
- 2xl `1.875rem`
- tight leading `1.25`
- normal leading `1.55`

### Spacing
Use existing variables:
- 4 / 8 / 12 / 16 / 20 / 24 / 32 px

### Radius
- small 8
- control 10
- medium 12
- card 16
- large 18

### Interaction geometry
- standard control height: 44 px
- minimum touch target: 44 px
- sidebar base width: 232 px (responsive source may override)
- topbar height: 76 px
- table cell padding: 11 px vertical / 14 px horizontal

Never hard-code a second palette when these variables already exist.

## 3. Canonical page anatomy

A normal Dashboard page should compose the existing primitives in this order when relevant:

1. canonical `foodex-admin-layout` / `foodex-admin-main`;
2. `foodex-page-header`;
3. page title + short business subtitle;
4. `foodex-header-actions` for primary page actions;
5. `foodex-tabs` for related functions inside the domain;
6. filter/operational toolbar;
7. content: KPI/cards/map/grid;
8. explicit state surface;
9. pagination;
10. modal/dialog/drawer for record create/edit/manage when appropriate.

Do not create a separate page chrome or duplicate sidebar/topbar.

## 4. Navigation model

### Sidebar
Sidebar selects **business domain**, not project/epic.

Examples:
- Vans
- Drivers
- Customers
- Finance / Invoices
- Operations / Routing
- Geography / Territories
- Sales Control
- Promotions / Marketing

### Tabs
Horizontal page-level tabs select related functions inside the domain.

Rules:
- preserve current workspace/context where practical;
- labels are business-readable;
- tabs must remain usable on narrow layouts; canonical CSS uses horizontal overflow rather than broken wrapping where needed.

### Administration
One Administration sidebar entry opens a true icon/card Admin Hub. Do not recreate a giant nested administration link tree.

## 5. Buttons and actions

### Primary
Use existing FOODEX primary action pattern:
- green background;
- white text;
- 44 px minimum touch height;
- current radius/spacing tokens;
- hover -> green dark.

Existing classes include:
- `.primary`
- `.button`
- `.btn.primary`
- `.foodex-primary`
- `.foodex-action-primary`
- `.foodex-filter-action`

Use the project's intended component/class for the nearby surface rather than inventing another alias.

### Secondary
Green-soft surface + green-dark text through existing secondary pattern.

### Destructive
Use existing danger treatment. Destructive mutation still requires explicit confirmation and correct backend authorization.

### Record rows
Use **one compact ellipsis menu** for multiple row actions.

Existing operational pattern:
- `.foodex-ops-actions`
- summary button
- `.foodex-ops-menu`

Do not spread View/Edit/Delete/Manage as four large buttons across a row.

## 6. Forms / Lookups

Every business-facing field must be classified:
A. Lookup / Master Data  
B. finite Enum  
C. structured Builder  
D. legitimate free input  
E. privileged advanced technical input

Rules:
- no routine raw DB IDs;
- no typed internal keys when an authoritative source exists;
- no raw JSON for normal business workflows;
- use authoritative Customer/Store/Van/Driver/Product/Selling Unit/Warehouse/Territory/Route/Order lookup;
- map-first for geography/polygons;
- optional "Add new" may open canonical create workflow if permission allows;
- after save, return to working context and refresh it.

## 7. Grid contract

Prefer current Orders/operations-style data grids.

Canonical classes/patterns:
- `.foodex-table`
- `.foodex-table-wrap`
- `.foodex-ops-grid`
- `.foodex-ops-toolbar`

Grid row should show enough business information to scan without opening detail:
- human name/reference;
- meaningful context;
- localized status badge;
- concise supporting metadata;
- one actions menu.

Never show relationship IDs as primary row content.

## 8. States

Use explicit current patterns:
- `.foodex-alert`
- `.foodex-state`
- `.foodex-empty-state`
- `.foodex-ops-state`

Dynamic screens must distinguish:
- loading;
- empty;
- error;
- stale/offline when applicable.

A stale map/location/data value must not look live.

## 9. Modal / operational dialog

Canonical pattern:
- `.foodex-modal-backdrop`
- `.foodex-modal`
- `.foodex-modal-header`
- `.foodex-modal-close`
- `.foodex-operational-dialog-host` for wider operational dialog

Current modal target:
- normal width up to ~620 px;
- operational up to ~920 px;
- max height constrained with internal scrolling;
- modal-open body scroll locked.

Use direct-record Modal/Drawer/detail context for View/Edit/Manage instead of routing users to a generic list.

## 10. Responsive contract

Current brand breakpoints:
- desktop: 1280+
- compact desktop: 1024+
- tablet: 768+
- mobile: below 768

Premium dashboard target:
- >=1280: multi-column reference layout;
- 1024–1279: KPI 2x2; chart regions may stack;
- 768–1023: sidebar becomes compact/drawer behavior; lower cards 2 columns;
- <768: single-column cards; >=44 px touch targets.

Rules:
- no page-level horizontal overflow;
- table wrap may scroll intentionally;
- page header becomes vertical when needed;
- tabs become horizontally scrollable;
- toolbars collapse deliberately;
- do not shrink text/actions until unusable.

## 11. Premium dashboard / KPI composition

For the main premium Dashboard, preserve the current contract:
- 4 equal-weight KPI cards;
- Orders & Revenue chart ~2/3;
- Orders Distribution ~1/3;
- lower operational cards such as Low Stock, Recent Orders, Quick Actions;
- live authoritative data, never screenshot hard-codes.

Colors have semantic meaning:
- green: active/revenue/success;
- orange: orders/warm operational accent;
- blue: in-transit/info;
- red: cancelled/error.

## 12. Charts

Reuse shared visualization primitives and `backend/public/assets/admin/foodex-visualization.css`.

Charts:
- render authoritative service values; do not invent financial/stock truth in JS;
- support loading/empty/stale/error as applicable;
- remain compact and operational, not decorative hero art;
- use sparklines for compact trend context;
- introduce a new visualization family only through the shared visualization contract.

## 13. Localization / direction

- Arabic: RTL.
- English: LTR.
- same hierarchy in both.
- numbers may use tabular-number treatment.
- raw enum/status/channel/role/type/payment/unit values must be localized.

## 14. Dashboard page Definition of Done

Before marking complete:
- canonical shell used;
- business-domain sidebar placement correct;
- tabs used for sibling functions;
- FOODEX page header/actions used;
- primary actions green/white;
- lookups replace IDs/JSON;
- data grid is informative;
- row actions consolidated;
- exact-record manage/edit/view works;
- states explicit;
- AR/EN and RTL/LTR verified;
- responsive widths verified;
- no hidden/orphaned route;
- existing compliance tests updated/added;
- runtime evidence provided when required.

## 15. Exact Dashboard primitive recipes from current production source

The following are the current shared implementation primitives in `_brand-components.blade.php`. Prefer them over page-local CSS.

### Standard page
- `.foodex-admin-layout`
- `.foodex-admin-main`
- `.foodex-admin-page`
- `.foodex-page-header`
- `.foodex-header-actions`
- `.foodex-tabs`

### Management/list page
- `.foodex-ops-shell`
- `.foodex-ops-toolbar`
- `.foodex-table-wrap` or current table wrapper
- `.foodex-ops-grid`
- `.foodex-ops-actions`
- `.foodex-ops-menu`
- `.foodex-pagination`

### Detail/manage
- `.foodex-modal-backdrop`
- `.foodex-modal`
- `.foodex-operational-dialog-host`
- `.foodex-modal-header`
- `.foodex-modal-close`
- `.foodex-ops-detail-grid`

### Forms
- `.foodex-form`
- `.foodex-premium-auto-form`
- `.foodex-control` / production-enhanced controls
- existing authoritative lookup/select components

### States
- `.foodex-alert`
- `.foodex-state`
- `.foodex-empty-state`
- `.foodex-ops-state`

### Actions
- primary: existing `.primary`, `.foodex-primary`, `.foodex-action-primary`
- secondary: existing `.secondary`, `.foodex-action-secondary`
- destructive: existing danger treatment
- multiple row actions: one `.foodex-ops-actions` ellipsis menu

A new Dashboard page that replaces these with bespoke equivalents is a UI/UX regression unless the shared system itself is deliberately being changed.

## 16. Dashboard archetype selection

When the owner only says “اعمل صفحة”:

- records/index/management -> use the Management/list recipe;
- record View/Edit/Manage -> use detail/manage inside direct record context;
- create/edit business object -> use Forms + authoritative lookups;
- finance/invoices/operations sibling functions -> use page tabs + management grid;
- analytics/dashboard -> use Premium Dashboard + visualization contract;
- geography/territory -> map-first interaction, not raw coordinate textboxes.

The nearest production domain page is the layout reference. Do not create a generic CRUD page first and “style it later”.


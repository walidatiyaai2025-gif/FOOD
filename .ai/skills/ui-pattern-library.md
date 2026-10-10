# Skill: FOODEX UI/UX — Production Pattern Library

This file is a source map for page generation. It lists the **real production primitives that exist today**. It is not a substitute for reading their current source before implementation.

When the owner says only `اعمل صفحة`, the worker must use these patterns before inventing markup, CSS, navigation, cards, states, or actions.

## 1. Laravel Dashboard / Admin Web

### Canonical sources
- `backend/resources/views/admin/_brand.blade.php` — brand tokens, Tajawal, spacing/radius/breakpoints.
- `backend/resources/views/admin/_brand-components.blade.php` — shared page/layout/action/state/modal/grid patterns.
- `backend/resources/views/admin/_sidebar.blade.php` — business-domain navigation.
- `backend/resources/views/admin/shell.blade.php` — canonical shell behavior.
- `backend/public/assets/admin/foodex-visualization.css` — shared chart primitives.

### Required shell primitives
Prefer/reuse:
- `.foodex-admin-layout`
- `.foodex-admin-main`
- `.foodex-admin-page`
- `.sidebar`
- `.foodex-page-header`
- `.foodex-header-actions`
- `.foodex-subtitle`

Do not build a second sidebar/topbar/page shell for a normal Dashboard page.

### Tabs / module navigation
Prefer/reuse:
- `.foodex-tabs`
- `.foodex-tab`

Tabs select sibling functions inside the current business domain. On narrow layouts they use horizontal overflow rather than broken multi-row wrapping.

### Cards / surface
Prefer/reuse the existing FOODEX card/panel patterns from `_brand-components.blade.php` and the nearest production page. Do not introduce a page-local Bootstrap visual language.

### Tables / operational grids
Prefer/reuse:
- `.foodex-table`
- `.foodex-table-wrap`
- `.foodex-ops-shell`
- `.foodex-ops-toolbar`
- `.foodex-ops-grid`

Current `.foodex-ops-grid` behavior is the reference for dense operations tables: shared cell padding, muted header row, hover state, business-readable values and contained overflow.

### Row actions
Prefer/reuse:
- `.foodex-ops-actions`
- `.foodex-ops-menu`

If a row has multiple actions, use one compact FOODEX-green ellipsis menu. Do not put View/Edit/Delete/Manage as four full buttons.

### States
Prefer/reuse:
- `.foodex-alert`
- `.foodex-state`
- `.foodex-alert-success`
- `.foodex-alert-error`
- `.foodex-empty-state`
- `.foodex-ops-state`

A dynamic page must distinguish loading/current/empty/error and stale/offline where the domain can retain old data.

### Modal / focused workflows
Prefer/reuse:
- `.foodex-modal-backdrop`
- `.foodex-modal`
- `.foodex-modal-header`
- `.foodex-modal-close`
- `.foodex-operational-dialog-host`

Create/Edit/View/Manage should operate on the exact record in a modal/drawer/detail context where the product contract calls for it.

### Form behavior
The shared brand runtime enhances normal forms and controls. Do not bypass it with one-off form styling unless a proven product requirement requires a new shared primitive.

Normal business fields must be Lookup / Enum / Builder / legitimate free input. Routine raw DB IDs, internal keys and JSON are prohibited.

### Reference pages to inspect before inventing a pattern
Choose the nearest relevant production page, for example:
- `backend/resources/views/admin/order-operations.blade.php`
- `backend/resources/views/admin/field-operations.blade.php`
- `backend/resources/views/admin/customer-360-index.blade.php`
- `backend/resources/views/admin/administration-hub.blade.php`
- `backend/resources/views/admin/reports.blade.php`
- `backend/resources/views/admin/driver-live-tracking.blade.php`

The nearest domain page beats a generic example.

## 2. Dashboard charts / analytics

Read `docs/design-reference/FOODEX_VISUALIZATION_CONTRACT.md`.

Shared web primitives:
- `.foodex-viz-line`
- `.foodex-viz-area`
- `.foodex-viz-sparkline`
- `.foodex-viz-bars`
- `.foodex-viz-donut`
- `.foodex-viz-progress`
- `.foodex-viz-heatmap`
- `.foodex-viz-legend`

Do not add a new chart library or rainbow palette for a page. Charts render authoritative service values and always need business-readable text context and state handling.

## 3. Customer Flutter — UI V3

### Canonical sources
- `apps/customer_app/lib/core/theme/customer_ui_v3_tokens.dart`
- `apps/customer_app/lib/core/theme/foodex_theme.dart`
- `apps/customer_app/lib/shared/customer_ui_v3/customer_components.dart`
- `apps/customer_app/lib/shared/customer_ui_v3/customer_states.dart`
- `apps/customer_app/lib/shared/customer_persistent_footer.dart`

### Reuse before creating widgets
- `CustomerCurvedHeaderSurface`
- `CustomerSearchPill`
- `CustomerBadge`
- `CustomerOutlineIconButton`
- `CustomerCategoryTile`
- `CustomerProductImage`
- `CustomerProductCard`
- `CustomerSkeletonBox`
- `CustomerCategorySkeleton`
- `CustomerProductCardSkeleton`
- `CustomerStateView`
- `CustomerPersistentFooterShell`
- `CustomerPersistentFooterDock`
- `CustomerPersistentFooter`

Customer pages should normally be composed from these primitives plus the current feature's existing widgets, not from a new independent visual kit.

### Customer shell/navigation
Use the existing commerce context and persistent footer. Retail and Wholesale destinations are already defined in `customer_persistent_footer.dart`; a new page must fit that information architecture instead of adding a one-off bottom navigation.

## 4. Driver Flutter

### Canonical sources
- `apps/driver_app/lib/core/theme/foodex_theme.dart`
- `apps/driver_app/lib/features/delivery/active/driver_active_journey.dart`
- `apps/driver_app/lib/navigation.dart`

`driver_active_journey.dart` is the current operational composition reference for compact filters, assignment rows/cards, one-line references, ellipsis actions, stale-data truthfulness and focused transition surfaces.

Use the Driver `FoodexTheme` and `FoodexBrand`; do not recreate a separate palette or Material defaults per screen.

## 5. Van Flutter

### Canonical sources
- `apps/van_app/lib/core/theme/foodex_van_theme.dart`
- `apps/van_app/lib/features/foundation/van_foundation_screen.dart`
- `apps/van_app/lib/features/foundation/van_screen_inventory.dart`
- `apps/van_app/lib/shared/van_action_button.dart`

Reuse:
- `FoodexVanTheme`
- `FoodexVanTokens`
- `VanActionButton`
- `VanIconAction`
- the existing Van foundation/navigation shell.

Do not invent a parallel Van information architecture. The approved 19-screen inventory remains the target unless an explicit product contract changes it.

## 6. Mobile visualization primitives

Shared Flutter analytics primitives live in `packages/foodex_visualization`:
- `FoodexTrendChart`
- `FoodexSparkline`
- `FoodexBarChart`
- `FoodexDonutChart`
- `FoodexProgressRange`
- `FoodexHeatmap`
- `FoodexChartLegend`

Reuse them across Customer/Driver/Van instead of adding screen-local chart implementations.

## 7. No-freeform rule

Before introducing any new UI primitive, the worker must be able to state:
1. which existing shared primitive was inspected;
2. why it cannot satisfy the requirement;
3. whether the new primitive belongs in a shared layer rather than the page;
4. how AR/RTL, EN/LTR, responsive behavior and states are covered.

If the worker cannot answer those four points, reuse the existing primitive.

## 8. Page generation default

Short owner request:
`اعمل صفحة <X>`

means:
- identify surface and business domain;
- inspect the nearest real production page;
- compose from the shared shell/theme/patterns above;
- use authoritative data and permissions;
- apply real FOODEX tokens/components;
- include business navigation, primary action, compact filters, exact-record actions and real states;
- AR/RTL + EN/LTR;
- responsive at the actual surface targets;
- run the relevant visual/runtime tests.

The result must look and behave like an existing FOODEX page, not like a generic Laravel/Flutter template.

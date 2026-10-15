# Dashboard Entity Lookup Contract

This contract defines the reusable FOODEX Dashboard picker behavior for managed entities. It supplements the Dashboard UI/UX, mobile-web, localization, accessibility, route-authority and performance contracts.

## Primitive

Use `public/assets/admin/entity-lookup.js` through a root marked `data-foodex-entity-picker`. The shared runtime owns interaction semantics; entity-specific controllers own authorization, scoping, filtering and result shape. Do not create a global unscoped entity API.

Required async adapter attributes:

- `data-entity-search-url`: authoritative scoped lookup endpoint.
- `data-entity-result-key`: JSON collection key.
- `data-entity-selection-mode`: `value` for a managed form value or `navigate` for route/filter navigation.
- `data-foodex-entity-search`, `data-foodex-entity-results`, and optional `data-foodex-entity-id`.
- Localized loading, empty, error, retry, selected/cleared and inactive-reference strings on the root.
- Result identity stays internal. Visible copy uses a primary human label plus disambiguating context such as SKU, barcode, store, channel, branch, email, or status.

Small fixed enums remain native `select`/radio controls unless search is materially useful. Growing managed entities use the async adapter. Do not replace small enums with network lookups.

## Async ownership and bounds

Lookup endpoints must enforce tenant/store/channel/branch authorization on the server and return bounded pages. The shared runtime debounces input, aborts the prior request, assigns a monotonically increasing generation, and ignores stale generations. It supports `next_page` for incremental loading without loading an entire entity table into the DOM.

A dependent parent change must clear an incompatible child identity before the next lookup. Call or trigger the shared clear path so both the visible label and managed value are invalidated; stale responses remain unable to restore the cleared child.

## Managed selection

Free text is never the submitted managed value. In `value` mode the hidden managed identity is independent of the current result page and is cleared as soon as the visible search text diverges from the selected label. In `navigate` mode selection writes the configured query parameter and navigates through the canonical route.

Inactive or historical references remain identifiable through localized inactive-reference state. Required, disabled and invalid behavior is driven by the actual form controls; client state never substitutes for server validation.

## Keyboard and assistive technology

The input is a combobox linked to a listbox through `aria-controls`. The runtime maintains `aria-expanded`, `aria-activedescendant` and option `aria-selected`.

- ArrowDown / ArrowUp: move the active option without moving DOM focus away from the search input.
- Enter: commit the active managed option.
- Escape: close the result surface and clear active-descendant state.
- Tab: close the result surface and preserve normal document focus traversal.
- Pointer selection and keyboard selection execute the same commit path.
- Loading, empty and error/retry state is announced through a polite status region.
- Results and controls must remain usable in Arabic RTL and English LTR and at 320/360/390/430, 768, 1024 and 1280 widths.

## Pilot integrations

Sales Control keeps `CommercialDashboardController::searchSalesControlProducts` as its B2B store-scoped authority and uses `navigate` mode.

Smart Ordering keeps `SmartOrderingMenuAdminController::products` as its active restaurant-catalog authority and uses `value` mode for `source_product_id`.

Orders and Catalog remain owned by their dedicated issues and are not migrated by this unit.

## Acceptance evidence

For any new integration, add focused contract or feature coverage for scoped endpoint behavior plus shared-runtime wiring. Material UI changes must pass the repository's exact-head required CI and Dashboard visual evidence jobs; static assertions alone do not certify runtime accessibility or responsive behavior.

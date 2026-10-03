# FOODEX 1.0.50 Release Notes

Status: customer catalog convergence, Favorites completion and Dashboard catalog repair.

## Release identity

- Dashboard: `1.0.50`
- Customer app: `1.0.50+50`
- Driver app: `1.0.50+50`
- Customer runtime/footer identity: `1.0.50`
- Driver runtime/footer identity: `1.0.50`
- Driver diagnostics current identity: `1.0.50`
- Driver diagnostics build identity: `50`
- Published `1.0.49` remains immutable and is not reused.

## Included changes

- Replace the fragile Wholesale marketplace product modal with the full product-details route while preserving exact store context and exposing product imagery, description, customer price, MOQ/order increment, stock information and add-to-cart behavior.
- Make Favorites functional across Wholesale and Retail product cards/details through the authenticated customer domain while preserving originating store/channel provenance.
- Keep Retail product detail behavior intact while wiring its previously inert heart/Favorites action.
- Render four product cards per row on normal phone widths across active Wholesale and Retail catalog surfaces, with a safe narrow-width fallback.
- Repair Dashboard Catalog Management after the production `/admin/catalog` undefined-variable failure, preserve product description/image and primary-image editing, and expose the direct authorized Catalog Management sidebar entry including SUPER_ADMIN.
- Include #817 / PR #818 regression coverage and green Backend validation, Customer Flutter/iOS validation, MySQL/Redis acceptance, UI Visual QA and mobile screenshot gates.

## Dashboard update bundle

- Target version: `1.0.50`
- Minimum current version: `1.0.6`
- Contains migrations: `true`
- Requires full redeploy: `false`
- SHA-256: `4e64da2da9f64494a6f9ff533fb4f83d402edd75ff03cffa1fc33d7a7d17e529`

## Explicit non-activation statement

This release does **not** automatically:
- change production minimum-supported AppVersion rows;
- enable force-update;
- enable Driver fresh-location enforcement;
- enable the Assistant in production.

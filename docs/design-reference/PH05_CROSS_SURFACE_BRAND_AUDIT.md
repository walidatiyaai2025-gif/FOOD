# PH-05 Cross-Surface Brand Audit

Issue #115 applies the canonical FOODEX design tokens without changing business rules.

## Web/Admin

The following primary Admin surfaces now load the shared FOODEX component token layer in addition to their existing functional layout:

- App Versions
- B2B Workspace
- Mobile & Push Settings
- Notifications
- Management Reports
- Users / Roles / Permissions
- System Update
- Translation Center

The premium B2C Dashboard already consumes the PH-05 token contract directly. Shared navigation is already FOODEX branded through `admin._sidebar`.

The shared component layer normalizes page background, surface cards, borders, inputs/focus rings, primary/secondary/danger/success actions, active report pills and sidebar surface. Existing page structure, form names, routes, permissions and controllers remain unchanged.

## Customer App

The application already inherits `FoodexTheme.light()`. PH-05 extends the shared theme to cover:
- NavigationBar surface/selected indicator.
- Filled and text actions.
- card/divider surfaces.
- canonical order-state color semantics.
- error text through the Material error token.

No guest browsing, authentication, cart, checkout, profile, B2B pricing or order rules are moved into Flutter.

## Driver App

The Driver app consumes the same numeric brand tokens and component themes. Assignment status presentation uses the canonical semantic mapping:
- delivered/completed → FOODEX green
- assigned/picked-up/out-for-delivery/in-transit → info blue
- pending/confirmed/processing/paid/accepted → FOODEX orange
- cancelled/refunded/failed → error red

Driver B2C/B2B channel isolation and transition rules remain backend authoritative.

## Regression rule

Any later visual change that introduces a new color or status treatment must update the shared token/theme source first. Hard-coded competing primary palettes on FOODEX primary surfaces are not accepted.

# FOODEX Customer — Owner-Approved UI/UX Implementation Reference

Status: OWNER APPROVED  
Execution Issue: #1038  
Runtime visual gate: #1042

## Approved design reference

The standalone Customer mockup is now an approved implementation reference for the production Customer App.

- Prototype archive branch: `archive/uiux-approved-mockups`
- Archived source path: `prototypes/customer_uiux_mockup/`
- Former standalone branch (consolidated): `prototype/customer-uiux-mockup`
- Last UI/layout commit: `0f17aba7ada2fad05f27bd969f0c54478c280079`
- Frozen mockup source: `prototypes/customer_uiux_mockup/lib/main.dart`
- Frozen mockup blob SHA: `12f2eccd53b0d09eb9b0c0963cd83d7641514426`

The reference must be treated as a **mockup mirror of the current Customer App ideas and journeys**, not as a separate product concept.

## Real FOODEX identity is authoritative

The production Customer App must preserve the real FOODEX brand system. The mockup does not replace production brand assets or tokens.

Authoritative production identity sources include:

- `apps/customer_app/lib/core/theme/customer_ui_v3_tokens.dart`
- Current token blob SHA at approval: `184e4ab2cd0130bb70a7f8863acf2582177339e6`
- Existing configured FOODEX/company logo and invoice identity assets
- Existing production localization and typography configuration

Production must use the real Customer UI V3 palette and typography:
- Deep Green `#00452F`
- Deep Green Strong `#003C2A`
- Deep Green Soft `#0A5B40`
- Lime `#9BE252`
- Lime Soft `#E7F8D7`
- Mint `#EDF7F1`
- Mint Strong `#C5E0CC`
- Ink `#17231D`
- Border `#DDE8E1`
- Alexandria-based production typography where configured

If a mockup placeholder logo, icon, sample wordmark, color, font, or fake company identity conflicts with the real FOODEX production identity, **the real FOODEX identity wins**.

## Current-app mirror rule

Implementation must keep the current Customer App information architecture and supported business journeys while adopting the approved mockup's polished visual composition.

The target includes the current app concepts and routes for:

### Platform / account
- Marketplace
- Login / Register
- Notifications
- Addresses
- Profile / Settings

### Retail
- Retail Store Home
- Categories
- Products
- Product Details
- Offers
- Favorites
- Cart
- Checkout
- My Orders
- Order Details / status presentation
- Order Tracking

### Wholesale / B2B
- Wholesale Home
- Business Dashboard
- Wholesale Products
- Wholesale Product Details
- Wholesale Cart
- Wholesale Checkout
- Wholesale Orders
- Wholesale Order Details
- Invoices
- Invoice Details
- Account Statement
- Purchase Reports
- Top Products
- Business Notifications
- Business Profile

The production route definitions and backend semantics remain authoritative. Do not create a second invented navigation model when the real route already exists.

## Navigation reference

Preserve the current persistent footer model.

Retail:
- Home
- Products
- Cart
- Orders
- Profile

Wholesale:
- Home
- Shopping
- Orders
- Invoices
- More

The approved mockup may refine visual spacing, hierarchy, card treatment, selected-state treatment and density, but it must not silently change the business meaning of those destinations.

## Order status / tracking rule

The Customer App already has an authoritative order tracking route and lifecycle/status behavior.

The production implementation must visibly expose:
- order/reference identity;
- current authoritative status;
- refresh action;
- last-updated indication;
- lifecycle/timeline progression;
- loaded / error / empty / stale behavior as applicable.

Do not fabricate live vehicle-map tracking if the authoritative production capability is not available. A real live map may only be shown when supported by actual backend/runtime data.

## Mock data prohibition

The standalone prototype uses fake display data only. Production must not copy mock records, fake stores, fake customers, fake orders, fake invoices or mock repositories into runtime paths.

Production screens must bind to the existing authoritative Customer APIs, auth/session, commerce context, order APIs, B2B data, notifications, addresses, payment/checkout rules and permissions.

## Exactness and exception rule

The mockup is the visual/UI reference for layout, density, hierarchy and interaction presentation **within the current Customer App journey**.

A deviation is allowed only when required by:
1. real FOODEX brand identity/assets;
2. authoritative backend/business behavior;
3. accessibility/platform constraints;
4. an existing production route/permission/security contract.

Any material deviation must be documented in #1038 with evidence.

## Acceptance

#1038 cannot close until:
- all current Customer canonical routes are audited against this reference;
- Retail and Wholesale persistent navigation match the current route model;
- production uses real FOODEX identity, not mockup placeholder branding;
- B2C order tracking visibly exposes status/timeline/refresh/last-updated behavior;
- AR/RTL and EN/LTR preserve the same hierarchy and usable density;
- fake/sample mock data is absent from production runtime paths;
- representative runtime screenshots/goldens are reviewed against this reference;
- #1042 verifies the integrated production build against this contract.

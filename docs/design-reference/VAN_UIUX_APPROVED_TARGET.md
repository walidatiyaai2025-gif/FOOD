# FOODEX Van — Approved UI/UX Target

Status: OWNER APPROVED  
Execution Issue: #1040  
Canonical production branch: `feat/1040-van-v42-full-sweep`

## Frozen design reference

The standalone Van mockup is the literal UI/UX target for the production Van application.

- Reference archive branch: `archive/uiux-approved-mockups`
- Archived source path: `prototypes/van_uiux_mockup/`
- Former standalone branch (consolidated): `prototype/van-uiux-mockup`
- Frozen approved SHA: `59335d21879736d075404c8cb1483dc9f0f09686`
- Reference source: `prototypes/van_uiux_mockup/lib/main.dart`
- Successful APK build run: `37573461843`

This reference is not an exploratory concept. It defines the intended production screen set, information architecture, visual hierarchy, density, FOODEX styling, Arabic/English parity and navigation model.

## Required screen inventory

The production Van application must implement all 19 target surfaces:

1. Login
2. Home Dashboard
3. Routes
4. Route Map
5. Route Detail
6. Customers
7. Visit Workspace
8. Customer 360
9. Product Catalog
10. Order Builder
11. Order Review
12. Orders
13. Offers
14. Wallet
15. Collection
16. Receipt
17. Remittance
18. Notifications
19. Profile & Settings

## Visual implementation contract

The production implementation must preserve the approved mockup composition as closely as the runtime platform permits:

- FOODEX green identity, light neutral background, white bordered cards and compact radii.
- Compact mobile-first spacing and no oversized nested cards.
- Data-first layouts with clear primary action hierarchy.
- Record identifiers remain on one line where they function as order/reference/customer codes.
- Compact overflow actions are used instead of multiple competing row buttons where appropriate.
- The bottom navigation model remains coherent across the primary operational surfaces.
- Route, customer, selling, collection and wallet flows remain visually connected.
- Arabic uses RTL and English uses LTR without changing the information hierarchy.
- Responsive layouts must preserve the same visual intent across supported phone widths.

## Runtime implementation contract

The prototype contains fake data only. Production code must never use the prototype's fake data as operational state.

Production screens must bind to authoritative FOODEX runtime services:

- real Van authentication/session and secure persistence;
- backend-authoritative routes, assignments and visits;
- canonical customer/store records;
- canonical products, selling units, commercial pricing and offer validation;
- canonical orders and order state;
- canonical wallet, collections, receipts and remittance state;
- existing authorization/permission semantics;
- automatic refresh/resume sync and explicit stale/offline states;
- existing push/session lifecycle.

Any production constraint that prevents exact visual parity must be documented in #1040 before changing the approved target.

## Placeholder prohibition

The existing placeholder-only Overview, Routes and Visits states are not acceptable as final implementation. They must be replaced with the approved real production surfaces.

## Verification

#1040 may close only after:

- all 19 target surfaces exist in the production Van runtime;
- every target surface is reachable through actual production navigation;
- fake/sample data is absent from production runtime paths;
- AR/RTL and EN/LTR are both verified;
- supported phone widths are visually verified;
- representative screenshot/golden evidence matches the approved target;
- backend semantics and permissions remain authoritative;
- #1042 validates the integrated runtime against this frozen reference.

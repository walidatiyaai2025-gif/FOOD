# PH-05 Premium FOODEX Dashboard Design Contract

Authoritative target for Issues #113–#116. This document encodes the user-approved 1536×1152 dashboard screenshot so every worker implements the same visual system.

## Desktop composition

Reference viewport: 1536×1152 screenshot, with the application canvas occupying the browser content area.

RTL layout:
- right sidebar: approximately 220–235 px, white surface, FOODEX brand at top
- top bar: approximately 76 px, white with subtle bottom border
- main content: light neutral background, 20–24 px gutters
- LTR mirrors the sidebar and directional controls

Header:
- FOODEX brand on sidebar
- centered/wide global search in top bar
- language selector, notifications, user identity/avatar
- greeting block and date selector below top bar

## Grid

Row 1: four KPI cards of equal visual weight:
1. Active users
2. Total orders
3. Total revenue
4. Products sold

Row 2:
- Orders & Revenue chart: roughly 2/3 width
- Orders Distribution donut: roughly 1/3 width

Row 3:
- Low Stock Products
- Recent Orders
- Quick Actions
- Mobile Apps promotion card remains associated with the navigation/sidebar area at desktop widths

Responsive:
- >=1280: reference multi-column arrangement
- 1024–1279: KPI 2×2, chart sections stack as needed
- 768–1023: sidebar becomes drawer/collapsible; lower cards 2 columns
- <768: single-column cards; touch targets >=44 px

## Visual language

Canonical colors come only from `docs/architecture/FOODEX_BRAND_SYSTEM.md`.
White cards, subtle `#E6EAF0` borders, soft shadow, 14–18 px radius.
Primary ink `#172033`, muted copy `#667085`.
FOODEX green drives active navigation and revenue line.
FOODEX orange drives order bars and warm accents.
Blue represents in-transit/info. Red represents cancelled/error.

## Dashboard data contract

Production UI must never hard-code screenshot values. All widgets use authoritative backend queries with permission/store scoping.

Required widgets:
- active users: active/present management users using a documented presence rule; if presence tracking is unavailable the UI must show an explicit unavailable/zero state rather than fake activity
- total orders: selected period/store scope
- total revenue: selected period/store scope, KWD formatting for current FOODEX domain
- products sold: sum of order-item quantity for revenue-recognized orders
- 7-day orders/revenue series
- status distribution: processing, out-for-delivery, delivered, cancelled (mapped from canonical order states)
- low-stock list: authoritative inventory thresholds
- recent orders: number, customer, item count, amount, status, relative time
- quick actions: only actions the effective permission set allows
- app promotion: Customer/Driver app links from mobile settings where configured

## B2C/mobile impact rule

Any metric or status normalization introduced for the dashboard must be reconciled with B2C Admin, Customer order tracking and Driver delivery status presentation. Backend terminology remains authoritative. Do not create dashboard-only states.

## Seed/visual QA

Issue #114 supplies deterministic non-production data sufficient to populate every widget. The reference reconstruction in this folder is a design aid; production views must render database/API data.

## Acceptance tolerance

Workers target pixel-level similarity in hierarchy, spacing, card shape, colors and responsive behavior. Browser chrome in the screenshot is not part of the application UI.

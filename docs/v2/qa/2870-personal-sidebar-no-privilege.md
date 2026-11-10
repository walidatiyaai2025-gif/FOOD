# FOODEX V2 #2870 — Personal Sidebar preferences

Source: P158, parent #2503. PR base: Foodex_V2 only.

## Placement and behavior
- Reuse/Extend the existing canonical Dashboard sidebar. No new route or navigation destination.
- Actor: authenticated Dashboard user; goal: favorite, reorder, hide and restore business groups that already passed the authoritative server-side navigation projection.
- UI: small toolbar in the canonical sidebar, inline focusable 44px buttons, keyboard access, native pressed state, Arabic/English labels, existing RTL/LTR and mobile web drawer.
- No separate business entitlement or permission toggle. A favorite is only a presentation priority, never a permission.
- The rendered list of authorized groups is the whitelist used when restoring stale or tampered localStorage. No link or route is fabricated. A stale favorite for a removed role/module/store cannot reappear.
- Personalization is local to a browser profile and user ID. It does not synchronize across browsers. Malformed/disabled localStorage falls back to the server order. Active destination group cannot be hidden.
- Individual page Favorites-at-top feature is separately tracked by V2 #2894, and this implementation makes no change to that independent acceptance lane.

## Authorization and impact
- All direct routes remain protected by existing Laravel policy/middleware; client UI controls do not call APIs or create roles, audit events or permissions. The test checks real 403 for B2C Dashboard and role-layout routes from a B2B Admin in both locales.
- Tenant/store/channel/module/entitlement/readiness are always evaluated by AdminNavigation and backend route guards before a DOM group exists. This issue changes neither.
- Customer Flutter: not affected; Driver Flutter: not affected; Van Flutter: not affected. No mobile source, API/schema/auth, dispatch or fulfillment change. Dashboard mobile web reuses the same sidebar drawer.
- Rollback: revert the partial + include. No database migration or mutable backend state. Leftover browser storage is inert.

## Evidence still required
- Focused test: php artisan test --filter=V2SidebarPersonalizationAuthorizationTest.
- Exact-head policy, localization, backend and V2 Isolation CI; do not merge with a red gate.
- Runtime manual browser verification at widths 320/390/768/1280 in Arabic RTL and English LTR; confirm keyboard focus, drawer, star/hide/reorder and stale access controls. This cannot be claimed as passed without execution evidence.

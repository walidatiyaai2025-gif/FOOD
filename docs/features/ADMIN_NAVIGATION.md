# Admin navigation hierarchy

FOODEX uses one permission-aware multi-level navigation model across the Management Dashboard, B2C workspace and B2B workspace.

## Behavior
- Related features are grouped under Overview, Operations, Catalog, Customers & Accounts, Stores & Channels, Marketing & Communications, Analytics & Reports, and Administration.
- A navigation item is emitted only when its route exists and the signed-in user has the required effective permission and channel access.
- API/controller authorization remains authoritative; navigation visibility is only a presentation layer.
- The active leaf and parent group are exposed for deep-linked routes.
- Only the group owning the active, server-authorized page receives a low-intensity background and a logical-start border; merely expanding a different group does not mark it as current. The existing active page link retains `aria-current="page"` in Arabic RTL and English LTR, including compact sidebar mode.
- Group open/closed state is persisted in browser storage with a per-user key.
- Navigation search filters leaf items without altering authorization.
- Arabic uses RTL and English uses LTR through the existing locale contract.
- The sidebar collapses on narrow screens and retains keyboard-native `details/summary` semantics.
- Sidebar nesting is capped at **three levels**: business group → page → child page. Saving a deeper hierarchy fails server-side with a localized field error; historical deeper or cyclic parent settings are rendered as accessible, authorized links with descendants lifted to the nearest valid group-level parent. No role/tenant/module permission can be gained by changing nesting.

## Regression requirements
Existing `/admin/b2c/{module}` and `/admin/b2b/{module}` deep links remain unchanged. New menu entries must never point to an undefined route.

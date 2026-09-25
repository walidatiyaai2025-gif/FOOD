# Admin navigation hierarchy

FOODEX uses one permission-aware multi-level navigation model across the Management Dashboard, B2C workspace and B2B workspace.

## Behavior
- Related features are grouped under Overview, Operations, Catalog, Customers & Accounts, Stores & Channels, Marketing & Communications, Analytics & Reports, and Administration.
- A navigation item is emitted only when its route exists and the signed-in user has the required effective permission and channel access.
- API/controller authorization remains authoritative; navigation visibility is only a presentation layer.
- The active leaf and parent group are exposed for deep-linked routes.
- Group open/closed state is persisted in browser storage with a per-user key.
- Navigation search filters leaf items without altering authorization.
- Arabic uses RTL and English uses LTR through the existing locale contract.
- The sidebar collapses on narrow screens and retains keyboard-native `details/summary` semantics.

## Regression requirements
Existing `/admin/b2c/{module}` and `/admin/b2b/{module}` deep links remain unchanged. New menu entries must never point to an undefined route.

# VAN ↔ Dashboard parity contract

Status: authoritative recovery contract for UIUX-V42-RECOVERY.

The owner-approved Van production target remains the 19-screen inventory defined by Issue #1040 and the frozen Van target. A missing Dashboard control/support surface must be implemented by its owning Dashboard lane; Van functionality must not be deleted, downgraded, or replaced with fake data to hide a Dashboard gap.

## Ownership model

- **#1035 — Shared/Admin Dashboard:** application administration, access policy, shared live operational visibility, Customer 360 support outside Field Operations, exact-order support, Van notification administration, profile/settings/application settings/publishing readiness.
- **#1036 — Commercial:** product/catalog commercial control, offer/promotion control, selling-unit/product-builder and commerce policy needed by Van ordering.
- **#1037 — Field Operations:** Vans, routes, visits, assignments, route maps, operational customers, territories/geography, wallet/custody/collection/receipt/remittance operational reconciliation.
- **#1040 — Van App:** production mobile implementation; consumes authoritative backend contracts and does not own Dashboard control-plane gaps.

## 19-screen parity map

| Van target surface | Dashboard counterpart / support plane | Owner | #1035 disposition |
|---|---|---|---|
| 1. Login | Administration Hub → Users & Permissions; Security user status/role/access policy; Van app administration | #1035 | **Covered** |
| 2. Home Dashboard | Shared Driver + Van Live Tracking and app administration/status visibility; operational route/visit summary belongs to Field Operations | #1035 + #1037 | **Covered in #1035 scope; Field Ops delegated** |
| 3. Routes | Field Operations routing / visits / assignments | #1037 | **Delegated — not a #1035 gap** |
| 4. Route Map | Field Operations fleet/routing maps | #1037 | **Delegated — not a #1035 gap** |
| 5. Route Detail | Field Operations route/visit/assignment management | #1037 | **Delegated — not a #1035 gap** |
| 6. Customers | Field Operations customer/visit operations; Customer 360 for shared support | #1037 + #1035 | **Customer 360 shared support covered** |
| 7. Visit Workspace | Field Operations visits and assignment transitions | #1037 | **Delegated — not a #1035 gap** |
| 8. Customer 360 | Dashboard Customer 360 identity/addresses/finance/orders/invoices support | #1035 | **Covered** |
| 9. Product Catalog | Sales Control / commercial catalog control | #1036 | **Delegated — not a #1035 gap** |
| 10. Order Builder | Commercial selling-unit/product/order policy + shared exact-order support after creation | #1036 + #1035 | **Exact-order support covered; builder delegated** |
| 11. Order Review | Commercial quote/order policy + shared Order Operations manage/view after creation | #1036 + #1035 | **Exact-order support covered; review policy delegated** |
| 12. Orders | Order Operations exact record, status, driver assignment, delivery evidence | #1035 | **Covered** |
| 13. Offers | Flash Offers / Marketing commercial administration | #1036 | **Delegated — not a #1035 gap** |
| 14. Wallet | Field Operations finance/custody reconciliation | #1037 | **Delegated — not a #1035 gap** |
| 15. Collection | Field Operations finance/collection custody | #1037 | **Delegated — not a #1035 gap** |
| 16. Receipt | Field Operations finance/receipt evidence and reconciliation | #1037 | **Delegated — not a #1035 gap** |
| 17. Remittance | Field Operations finance remittance approve/reject/reconcile routes | #1037 | **Delegated — not a #1035 gap** |
| 18. Notifications | Notification Center, Promotional Notification Campaigns, Push Provider settings/test delivery; Van audience/app supported independently | #1035 | **Covered** |
| 19. Profile & Settings | Security/Profile, App Versions, Mobile Settings, Push Provider, Store Submission and Reviewer/Test Account administration | #1035 | **Covered** |

## #1035 concrete Dashboard counterparts

The following routes/surfaces are the shared/admin control plane required by the Van target:

- `/admin/administration` — one-entry Admin Hub with explicit Customer / Driver / Van application cards.
- `/admin/security` — Users & Permissions, user status, roles and access policy.
- `/admin/driver-live-tracking` — combined Driver + Van identity/status visibility with stale/online/offline semantics.
- `/admin/customer-360` — shared customer identity/address/finance/order/invoice support outside Field Operations.
- `/admin/operations/orders` — exact order lookup/view/manage, driver assignment/status and delivery evidence.
- Notification Center / Promotional Notification Campaigns — Van is an independent audience/application target.
- `/admin/settings/app-versions` — per-app release/version policy.
- `/admin/settings/mobile` — Customer / Driver / Van runtime settings, push, store readiness and reviewer/test-account administration.

## No-gap rule for #1035 closure

#1035 has no unresolved Van↔Dashboard gap when all of the following are true:

1. Administration is one Sidebar entry and opens the real Admin Hub.
2. Customer, Driver and Van are first-class applications with per-app Preview/App Version/Mobile Settings actions where authorized.
3. Security/access policy can administer Van users/roles/permissions.
4. shared Live Tracking exposes distinct Driver and Van runtime identity and truthful state.
5. Customer 360 and Order Operations provide shared support outside sibling-lane ownership.
6. Notification administration supports Van independently.
7. App Version / Mobile Settings / Push / Store Submission / Reviewer administration support Van independently.
8. Route/visit/territory/finance custody gaps are owned by #1037, and product/offers/order-builder commercial gaps are owned by #1036; those delegated domains are not reimplemented by #1035.
9. Integrated gate #1042 verifies the combined runtime after sibling lanes converge.

This contract defines capability parity, not pixel-for-pixel duplication of the Van mobile layout.

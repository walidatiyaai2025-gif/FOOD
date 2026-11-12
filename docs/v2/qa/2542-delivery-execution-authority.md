# V2 #2542 — P212 Delivery & Execution sidebar authority

Status: **implementation PR — automated acceptance pending exact-head CI**  
Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P212; parent #2503.  
Integration: **Foodex_V2 only**; no `main`, production, release, cPanel, or APK changes.

## Placement and discoverability
- Domain: Orders & Fulfillment; user goal: inspect and execute authorized order/dispatch workflows, not discover a fictitious P212 page.
- Reuse: existing `operations` group / `admin.nav_groups.operations` (Orders & Operations / الطلبات والعمليات) with the existing `order_operations` link.
- Route: `admin.operations.orders.index` → `/admin/operations/orders`; privilege: effective `orders.view`, reconciled with the same server HTTP authorizer. The operational flow remains at the canonical Orders grid and its scoped status/dispatch actions.
- Retail-specific child: existing `b2c_orders` module link, with actual authorized store_id in the URL; does not select an unrelated store's context.
- Group visibility: empty groups vanish; permission-denied order links vanish even if an independently granted tracking child survives. No independent Sidebar visibility policy and no new route/Sidebar entry.
- Desktop/mobile: existing Dashboard responsive sidebar/off-canvas, green primary actions, Orders grid, and compact row actions are reused; no new markup is introduced. AR and EN labels are taken from the existing canonical language catalog.

## Security and test matrix
`backend/tests/Feature/V2DeliveryExecutionSidebarAuthorityTest.php` covers:
1. Authorized Wholesale `orders.view` → real named route and HTTP 200 in Arabic RTL and English LTR; no Retail orders child.
2. Roleless and unrelated-reporting actors → no Orders/Operations entry and HTTP 403.
3. Multi-role actor `orders.view` + `drivers.tracking.view` → both projected; an explicit global DENY of `orders.view` hides only Orders, keeps tracking, and HTTP denies Orders.
4. Retail Store Admin → Retail Orders link carries the actor's authorized store, not a foreign store, with a registered destination and no Wholesale Driver child.

The existing UI owns actual order actions. This test change does **not** add, broaden or bypass dispatch/update/approve permissions, and does not change store or channel execution rules.

## Impact assessment
| Surface | Impact | Evidence / next required gate |
|---|---|---|
| Backend | Tests only | No API/permission/route implementation change; Laravel feature test and exact-head Required CI must pass |
| Dashboard | Tests only | Reuses existing authorization-projected Orders & Operations sidebar; no changed UI source |
| Customer | Not affected | No Customer source/API/auth schema/contract or public order-state change |
| Driver | Not affected | No Driver source/API/auth schema/contract; B2C=Driver-only remains |
| Van | Not affected | No Van source/API/auth schema/contract; B2B=Van-only remains |

This issue remains OPEN pending full P212 source acceptance and final manual responsive/accessibility review under #2503/#2750. Source read-only evidence is not production UAT. Any failed automated test, lint, security, localization, or exact-head CI result is a blocker; repair on the same branch/PR. No deploy, APK builds, release tags or production evidence is claimed.

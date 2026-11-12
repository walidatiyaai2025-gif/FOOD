# V2 #2542 — P212 Delivery & Execution sidebar authority

Status: **source-level implementation complete; fresh exact-head CI and integration pending**  
Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P212; parent #2503.  
Integration: **Foodex_V2 only**; no `main`, production, release, cPanel, or APK changes.

## Placement and discoverability
- Domain: Orders & Fulfillment; user goal: inspect and execute authorized order/dispatch workflows, not discover a fictitious P212 page.
- Reuse: existing `operations` group / `admin.nav_groups.operations` (Orders & Operations / الطلبات والعمليات) with the existing `order_operations` link.
- Route: `admin.operations.orders.index` → `/admin/operations/orders`; privilege: effective `orders.view`, reconciled with the same server HTTP authorizer. The operational flow remains at the canonical Orders grid and its scoped status/dispatch actions.
- Retail-specific child: existing `b2c_orders` module link, with actual authorized store_id in the URL; does not select an unrelated store's context.
- Group visibility: empty groups vanish; permission-denied order links vanish even if an independently granted tracking child survives. The Orders entry now uses the same `OperationalTenantScope::allowedStoreIds` Retail/Wholesale-principal channel guard as `OrderOperationsController::availableOperationalChannels`, not a broad global `orders.view` role grant. A custom global role with the permission but no B2B/B2C operational channel sees no dead Orders link; HTTP still denies 403. No independent Sidebar visibility policy and no new route/Sidebar entry.
- Desktop/mobile: existing Dashboard responsive sidebar/off-canvas, green primary actions, Orders grid, and compact row actions are reused; no new markup is introduced. AR and EN labels are taken from the existing canonical language catalog.

## Security and test matrix
`backend/tests/Feature/V2DeliveryExecutionSidebarAuthorityTest.php` covers:
1. Authorized Wholesale `orders.view` → real named route and HTTP 200 in Arabic RTL and English LTR; no Retail orders child.
2. Roleless and unrelated-reporting actors → no Orders/Operations entry and HTTP 403.
3. Multi-role actor `orders.view` + `drivers.tracking.view` → both projected; an explicit global DENY of `orders.view` hides only Orders, keeps tracking, and HTTP denies Orders.
4. Retail Store Admin → Retail Orders link carries the actor's authorized store, not a foreign store, with a registered destination and no Wholesale Driver child.
5. An unlisted custom global role holding `orders.view` but lacking any configured B2B/B2C channel grant cannot see Orders in the Sidebar, and direct HTTP remains 403 even with active B2B/B2C stores. A canonical B2B OPERATIONS role and retail store admin remain allowed.

The existing UI owns actual order actions. This test change does **not** add, broaden or bypass dispatch/update/approve permissions, and does not change store or channel execution rules.

## Impact assessment
| Surface | Impact | Evidence / next required gate |
|---|---|---|
| Backend | Scoped navigation projection + tests | No API/permission/route/authorization grant change; reuses same HTTP operational-channel store guard; Laravel feature test and exact-head Required CI must pass |
| Dashboard | Corrected existing Sidebar authorization projection | No new markup/screen/route. Reuses current responsive Orders grid and AR/EN, RTL/LTR navigation. |
| Customer | Not affected | No Customer source/API/auth schema/contract or public order-state change |
| Driver | Not affected | No Driver source/API/auth schema/contract; B2C=Driver-only remains |
| Van | Not affected | No Van source/API/auth schema/contract; B2B=Van-only remains |

This issue remains OPEN pending full P212 source acceptance and final manual responsive/accessibility review under #2503/#2750. Source read-only evidence is not production UAT. Any failed automated test, lint, security, localization, or exact-head CI result is a blocker; repair on the same branch/PR. No deploy, APK builds, release tags or production evidence is claimed.

## Security source fix and reconciliation

- **Reused** existing Orders & Operations Sidebar/domain and authorized Orders page, no new page, no Sidebar group and no permission grant. Route `admin.operations.orders.index`, canonical controller `OrderOperationsController::availableOperationalChannels`.
- `AdminNavigation::orderOperationsItem()` projects the same channel restrictions: effective `orders.view` on at least one allowed Retail B2C store OR the canonical B2B wholesale principal, not any unrelated/global role carrying that permission. Direct HTTP continues to enforce the same guard; NO production access rights are broadened.
- Existing `WholesalePrincipal` and `OperationalTenantScope` remain authoritative. No changes to Customer / Driver / Van APIs, Flutter models, dispatch statuses or finance.
- The single existing issue branch/PR is reconciled with current `Foodex_V2` using a non-force two-parent commit, preserving prior #2523 and #2654 changes in shared AdminNavigation; no main promotion.
- P212 source and automated AR/EN/RTL, authorization allow/deny, store isolation and negative HTTP acceptance require exact new-head checks. Manual 320px/mobile-web/keyboard browser UAT remains deferred to V2 master #2503/#2750 and is not asserted as performed.

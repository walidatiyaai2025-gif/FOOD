# V2 #2879 / P169 — Business-domain grouping for Dashboard Sidebar

Source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, P169; parent #2503. Delivery target: **Foodex_V2 only**.

## Reuse decision and canonical authority

P169's requested business taxonomy is **already implemented** in the canonical `AdminNavigation::groupsFor(User)` plus `AdminSidebarManager::registry/apply` and the existing Dashboard `admin._sidebar` renderer. This Issue adds the missing durable regression and explicit actor/source mapping instead of introducing duplicate groups, routes, screens or policy.

The approved current grouping uses business domain names, not one large technical ERP menu:

| User goal / domain | Canonical group | Representative registered entries |
| --- | --- | --- |
| Sales and product/catalog work | `catalog` | `commercial_sales_control`, `catalog_management`, `b2b_products` |
| Inventory and warehouse | `catalog` (supply/product domain) | `b2c_inventory`, `b2b_inventory`, `erp_wms` |
| Orders and operations | `operations` | `order_operations`, `b2c_incoming_orders` |
| Finance and Treasury | `finance` | `treasury_master`, `erp_finance`, `erp_treasury` |
| Administration and permissions | `administration` | `administration_hub`, `sidebar_role_access` |

`erp_procurement` remains in `catalog`; `erp_bi` remains in `analytics`. Existing P358 issue #2882/PR #3015 supplies ERP business-domain placement. This P169 contract tests the broader sales/orders/inventory/finance/administration taxonomy without duplicating P358. The domain group labels are translated by existing AR/EN keys. The existing group/paging and local customization remain presentation only; an item never appears merely because its group exists.

**Placement:** Reuse the existing SIDEBAR + business-domain group; no new navigation destination. Actor entry point is the existing Dashboard Sidebar. `domain, role, scope, module/readiness, permissions` continue to be resolved by canonical Laravel services and actual protected routes. B2B global admin sees only authorized wholesale destinations, B2C store admin only assigned-store retail destinations; ERP disabled/uncertified or missing entitlements remain omitted by the existing authority, regardless of static category ownership. Super Admin has no bypass around tenant, authorization or finance SoD.

## Regressions

The new `V2BusinessDomainSidebarGroupingTest` proves:
- source registry and persisted default layout agree for sales/catalog, inventory/WMS, orders/operations, finance and administration; P358 ERP modules stay in their proper business domains;
- every group has Arabic and English locale labels, with real Dashboard HTTP responses in AR RTL and EN LTR;
- B2B actor's authorized catalog/orders entries are in their real business groups, while B2C inventory is absent; Orders HTTP path responds only with effective authorization;
- B2C assigned store sees authorized retail inventory; incoming-orders may correctly be absent when its actual module/route readiness denies display, but if rendered it must belong to Operations. No wholesale inventory, B2B dashboard or foreign-store access is conferred;
- roleless and inactive actors receive no authoritative navigation and no direct-URL access.

No server RBAC rules, routes, entitlements, group registry, database, API payload or user preferences are changed.

## Impact matrix and acceptance

| Surface | Affected | Assessment |
| --- | --- | --- |
| Laravel backend | Regression only | Added Feature test against real authorization and Dashboard HTTP |
| Dashboard web/mobile web | Existing grouping validated | No UI/runtime changes; existing AR/EN RTL/LTR responsive Sidebar remains authoritative |
| Customer Flutter | No | No Customer files, API payload or channel grant changed |
| Driver Flutter | No | No Driver files, dispatch or B2C-only delivery change |
| Van Flutter | No | No Van files, fulfillment, routing or B2B-only delivery change |

Require Repository Policy, V2 Isolation and Required CI on final PR head, including PHP syntax/Pint/PHPStan/security, Laravel, localization and MySQL/Redis acceptance when applicable; fix any failure in the original branch/PR, not with bypasses.

**MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL** per owner override on master #2503; isolated staging browser verification at 320/360/390/412/430/768/1024/1280px and keyboard/screen reader remains in final #2750. Documenting the existing real business-domain behavior and passing automated tests is technical requirement delivery, not production readiness. No main integration, tag, release or deployment.

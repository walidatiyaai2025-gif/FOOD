# V2 P118 — Wholesale ERP navigation in business-process order

**Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, line `P118`. **Leaf issue:** #2848; **coordinator:** #2503.
**Integration branch:** `Foodex_V2` exclusively. No `main`, tags, releases or deployments.

## Authoritative Wholesale ERP journey

The menu and ERP Center use the same business sequence:

| Position | Sidebar item | ERP card | Purpose |
| --- | --- | --- | --- |
| 10 | `erp_center` | ERP Center heading | Overview, module health and authorizations |
| 20 | `erp_customer_service` | `customer_service` | Customer/sales service and orders |
| 30 | `erp_procurement` | `procurement` | Procurement and supplier workflows |
| 40 | `erp_wms` | `wms` | Inventory and warehouse |
| 50 | `erp_finance` | `finance` | Finance and journal operations |
| 60 | `erp_treasury` | `treasury` | Treasury and collection |
| 70 | `erp_bi` | `bi` | Business intelligence/reports |
| 80 | `erp_my_work` | `my_work` | Approvals, exceptions and operational control |

The ERP Center is its own heading/navigation destination, not a duplicate workbench card. The sequence is now aligned across **`AdminNavigation::groupsFor`**, `admin_sidebar.items.*.order` (unique positions, no duplicate `50`), and **`ErpCenterController`** card order. `AdminSidebarManager` remains the only layout projection for ordinary users: explicitly configured historical admin ordering is respected instead of overwritten. Owner diagnostic inventory remains in its canonical source order.

The source-to-runtime link is **projection-only**. Existing canonical route registration and server-side permission, module-state, scope and entitlement checks are unchanged. The ERP group does not grant authority; hidden/disabled/unentitled/unregistered destinations cannot be reintroduced by a layout move.

## Actor / permission / channel boundary

| Actor | Navigation behavior | HTTP authorization contract |
| --- | --- | --- |
| SUPER_ADMIN | ERP journey in AR/EN; all eligible modules available on disposable local test bootstrap | Existing verified administrative route permissions, direct HTTP still checked |
| FINANCE (global) | Only actually authorized and available ERP links; configured ordering cannot force procurement visibility | No `procurement.suppliers.view` grant from a menu preference |
| Retail B2C_STORE_ADMIN (store-scoped) | No Wholesale `erp` group even when the role has its own store UI | No wholesale permission inherited from store assignment |
| Customer-service-only profile | Existing targeted customer service workspace not silently converted to full ERP menu | Existing channel and service authorization |

**Source of truth:** `User` permission and store scopes, `ModuleRegistry`, `ModuleEntitlementResolver`, route guards; `AdminNavigation` projects them, then `AdminSidebarManager` may hide/rename/reorder but never grant access.

## Acceptance / regression

New `backend/tests/Feature/V2WholesaleErpNavigationSequenceTest.php` uses seeded disposable database and real authenticated Laravel HTTP for:
- exact 10→80 registry order, no duplicate slots, one canonical ERP group;
- AR/EN (RTL/LTR) ERP Center card order, authenticated HTML order and live Sidebar children matching the registry;
- every emitted Sidebar route name registered in Laravel;
- Retail store actor receives no Wholesale ERP group and has no procurement permission;
- forged layout promotion of procurement cannot elevate FINANCE role or introduce otherwise unauthorized/disabled ERP links.

```sh
cd backend
php artisan test --filter=V2WholesaleErpNavigationSequenceTest
vendor/bin/pint --test app/Support/AdminNavigation.php app/Http/Controllers/Admin/ErpCenterController.php tests/Feature/V2WholesaleErpNavigationSequenceTest.php
```

Require latest exact-head Repository Policy, V2 Isolation Gate, Laravel Tests, Pint, PHPStan, Composer Audit, AR/EN Localization, MySQL/Redis fresh install/upgrade/recovery, `required-ci-gate`, and any applicable UI checks green. Static contract and HTTP render coverage is not physical-browser viewport QA; no new HTML/CSS or responsive interaction was introduced.

## Backend, Dashboard, and mobile impact matrix

| Surface | Impact | Evidence |
| --- | --- | --- |
| Backend | Navigation sequencing only in existing PHP controller/helper/config; no migrations or API shape | Feature tests and full CI |
| Dashboard | **Affected**: ERP Sidebar and ERP Center presentation ordering; existing Arabic and English labels, RTL/LTR and tab/action URLs | Real HTTP tests |
| Customer Flutter | **Not affected**: no customer API, session, role, payload or widget change | Only backend Dashboard server-render navigation files changed |
| Driver Flutter | **Not affected**: no driver API, dispatch, orders or lifecycle change | Same |
| Van Flutter | **Not affected**: no Van API, routes, territory, orders or lifecycle change | Same |

FOODEX B2B **VAN ONLY**, B2C **DRIVER ONLY** remains untouched. No mobile-specific V2 issues are warranted for a pure Dashboard menu-order change. Existing historical Sidebar administrator customization is preserved rather than migrated or deleted.

**Closure:** one issue-scoped branch, one PR to `Foodex_V2`; cite exact commit/CI and close only if all applicable acceptance is satisfied. Never assert that the whole ERP rollout is operational because a menu is visible (governed separately by #2838/#2839).

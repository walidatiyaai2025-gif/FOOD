# FOODEX V2 #2779 — T028R02 Retail Inventory layout vs server authorization

Source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx / T028R02, parent #2503. Only integration target: Foodex_V2.

## Canonical behavior

Reuse AdminNavigation::groupsFor, AdminSidebarManager::apply and the existing admin_sidebar_items / admin_sidebar_groups presentation settings; these are never permissions. The existing B2C inventory route admin.b2c.module and its inventory.view permission+store scope are the only server authority. Setting is_enabled=false for the Retail Inventory item or its Catalog group can hide the navigation link without revoking a legitimate assigned-store HTTP 200. It cannot grant another store or Wholesale channel access, and restoring the link cannot create a new permission.

Existing #2792 / PR #2959 already tests the B2B Catalog item/group behavior and missing-role-permission 403. This T028R02 regression adds the missing **Retail Store A versus Store B** and B2C vs B2B negative matrix, rather than duplicate those B2B tests. Real module entitlement/readiness guards remain independent from cosmetic layout.

## Server-side matrix

| Persona and setting | Sidebar | HTTP |
| --- | --- | --- |
| Active RETAIL_INVENTORY assigned Store A, item disabled | b2c_inventory hidden | own Store A B2C Inventory 200 |
| Same user, whole Catalog group disabled | b2c_inventory hidden | own Store A B2C Inventory 200 |
| Same user requests unassigned Store B | no foreign-store grant | 403/404 |
| Same user requests B2B Inventory | no Wholesale access | 403 |
| Restore item/group enabled | valid authorized item reappears | own Store A Inventory 200 |

New test: backend/tests/Feature/V2RetailInventoryLayoutHttpBoundaryTest.php. Seeded user/store isolation, real Laravel GET/permission assertions, AR/RTL and EN/LTR, negative cross-store and cross-channel requests, and permission preservation. Tests only; no production settings or accounts changed.

## Surface assessment

- Backend and Dashboard: New Laravel regression only. No route/permission/entitlement, Sidebar renderer, live user, audit/finance or UI mutation.
- Customer: NOT AFFECTED. No Customer API/identity/order/deeplink/checkout changes.
- Driver: NOT AFFECTED. No Driver login/GPS/assignment/notification/Driver-only B2C dispatch changes.
- Van: NOT AFFECTED. No Van login/routes/territories/visits/Van-only B2B dispatch changes.
- Finance and Maker/Checker: Not affected. No ledger, approval or payment write.
- AR/EN: Locale and HTML direction asserted for own-store allowed route; server-side 403/404 remains identical across locales.
- Accessibility and responsive browser acceptance: unchanged UI, deferred for program-wide isolated V2 staging QA under #2503.

## Delivery and gates

One Issue #2779, one branch test/2779-v2-retail-inventory-layout-http-boundary and one PR to Foodex_V2 only. Require exact-final-head Repository Policy, V2 Isolation and Required CI Gate, including applicable PHP/Pint/PHPStan/security/OpenAPI, Laravel, localization and MySQL/Redis fresh-install/upgrade/recovery jobs. Record GitHub run URLs and merge SHA after verification; repair red CI on same branch/PR.

Per explicit #2503 owner override (2026-11-10), defer only manual browser/UAT until final V2 acceptance, not code or mandatory automated gates. Never merge into main or publish, tag, release, deploy, or touch live cPanel.

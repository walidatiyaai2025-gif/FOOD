# FOODEX V2 #2877 — clear business-facing ERP Sidebar module names

**Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` / P167; master #2503. **Merge target: `Foodex_V2` only.** Production, `main`, tags and deployment are explicitly excluded.

## Placement and visible business contract

- **Domain/entity:** enterprise business operations and their existing ERP workspaces.
- **Actors:** authorized platform/wholesale roles; retail-store-only users have no platform ERP permission.
- **Goal:** recognize the actual operation without decoding technical module codes.
- **Existing parent and placement:** reuse the canonical `AdminNavigation::groupsFor` ERP group and `admin._sidebar`; retain `admin.erp.index`, `admin.erp.wms.index`, `admin.erp.customer-service`, `admin.erp.bi`, and `admin.action-center.index` as their existing authorities. No new page, modal, menu item, permission or independent visibility rule.
- **Behavior:** only four pre-existing `admin.erp_center.modules.*.title` translations are updated, preserving stable PHP translation keys, routes, module registry/entitlements/readiness, saved bilingual overrides and role/store authorization. UI title changes do not enable unavailable modules or bypass controller 403/404.

| Existing module/title key | Arabic label | English label |
| --- | --- | --- |
| `wms` | إدارة المخازن والمخزون | Warehouses and Inventory |
| `customer_service` | خدمة العملاء والحسابات | Customer Service and Accounts |
| `bi` | التقارير وتحليلات الأعمال | Reports and Business Analytics |
| `my_work` | مهامي والاعتمادات | My Tasks and Approvals |

Technical module codes **remain unchanged** behind the scenes. The existing ERP group and ERP Center terminology are not rebranded by this atomic P167 change; the independently owned P118 group/order decisions remain under #2848. The merged #2880 wholesale/retail terminology is preserved.

## Security, localization and responsive evidence

- `V2ErpBusinessNavigationLabelsTest` checks all eight resolved Arabic/English strings, the canonical `erp_center` and `erp_my_work` item routes for B2B_ADMIN, actual RTL/LTR rendered Dashboard navigation, and a RETAIL_INVENTORY user with assigned store who can still open retail inventory but gets HTTP 403 for both platform ERP and Procurement.
- Module entitlements/readiness and disabled-route 404 remain covered by the existing #2796 `V2SidebarModuleEntitlementAvailabilityTest`; no database, migrations, policies, permissions or API contract changed.
- Dashboard desktop/tablet/mobile-web: the same authorized Sidebar labels are used in the responsive drawer, and no CSS/DOM/interaction changes were made. Exact-head Laravel and localization CI validate content; an independent browser/keyboard/overflow inspection on isolated V2 staging remains required before claiming full visual acceptance.
- Customer App: **not affected**, because customer API, Flutter localization, payloads and account routing do not change.
- Driver App: **not affected**, because driver role, dispatch, API and Flutter files do not change.
- Van App: **not affected**, because B2B VAN-only fulfillment, visits, routes, API and Flutter files do not change.
- Data preservation: zero schema or data mutations. Rollback is a translation-only revert of these four titles, preserving live user records and sidebar customizations.

## Verification and acceptance

Focused: `cd backend && php artisan test --filter=V2ErpBusinessNavigationLabelsTest`.
Required: PHP/Pint/PHPStan, AR/EN localization, backend suite, MySQL/Redis install/upgrade/recovery acceptance, Repository Policy, V2 Isolation and exact-head Required CI. Keep the Issue open if independent responsive browser acceptance is still missing; do not claim staging/prod operation without actual evidence.

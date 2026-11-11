# FOODEX V2 — #2691 / T122R11 Warehouse Manager authority

Status: **partial executable regression / source acceptance pending**. Integration target **Foodex_V2 only**. Parent: #2503.

## Source and canonical decision

- Original source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, `T122R11`: **مدير المستودع / WAREHOUSE_MANAGER**.
- Reuse the existing `BuiltInJobRoleCatalog`, `CoreReferenceSeeder`, `Role::hasPermission` authorization, `ModuleRegistry`, `AdminNavigation` and canonical WMS services. Do **not** introduce another role model, new page or independent Sidebar visibility rule.
- Existing global, tenant-business-scope role requires `inventory_wms`; `procurement` is optional. Its exact seeded permission bundle is `inventory.view`, `inventory.adjust`, `inventory.manage`, `catalog.view`, `reports.view`. It has no finance, order dispatch, user/security, or approval grants.
- Arabic: **مدير المستودع**; English: **Warehouse Manager**. Existing translations are reused, with no new UI strings, routes or layouts.

## Delivered bounded regression

`backend/tests/Feature/V2WarehouseManagerRoleAuthorityTest.php` asserts:

1. Canonical catalog metadata, system role/seeded permission correspondence and AR/EN labels.
2. The required module being disabled revokes the entire effective role bundle while preserving the historical assignment; direct WMS HTTP is forbidden.
3. The enabled module grants exactly the intended inventory/catalog/report permissions, not Finance, Security or Orders management.
4. Direct Security Center HTTP and Security API mutations are forbidden; an explicit `inventory.view` DENY overrides that grant without escalating other permissions.
5. A global warehouse manager assignment is not a store-scoped grant merely because a store identifier is supplied.

This is a **test-only change**, not a certification of every user journey or a production permission update. PHP syntax was checked before upload. Exact-head Laravel/Pint/PHPStan/security, localization and MySQL/Redis Required CI must still be observed in GitHub.

## Known unresolved source-level boundary

The current `backend/config/admin.php` B2B `global_roles` list does **not** include `WAREHOUSE_MANAGER`. Do not broaden that shared channel admission list by itself: `TenantContextResolver`, operational tenant scope and other cross-domain actions consume it. #2699 / draft PR #3179 owns this shared path and explicitly warns about cross-domain permission and SoD escalation, while #2858 / draft #3065 is still enforcing fail-closed ERP operational readiness.

**Before ready/merge/close:** prove the authorized real Warehouse Manager WMS entry route and full cross-store/warehouse HTTP action behavior, maker/checker as applicable, module entitlement/readiness, deny precedence and multi-role cases against the current source; coordinate any needed shared runtime change with existing file owners. Do not pass a fabricated readiness certificate or expand B2C store access. Reuse the original #2691 branch/PR. No second implementation PR.

## UI placement / per-surface impact

- **Backend / API:** Existing RBAC, module lifecycle and WMS authorization reused; this increment adds only Laravel feature tests. No API contract or runtime route changed.
- **Dashboard:** Existing Inventory/WMS business navigation is the expected entry point under Reuse → Extend → Compose → New Screen. Real global Warehouse Manager B2B accessibility is an **unproven source blocker**, not a UI acceptance pass. No new Sidebar entry.
- **Customer:** no changed API, pricing, order lifecycle, auth response or Flutter code in this test-only increment; VAN-only B2B delivery remains invariant.
- **Driver:** no changed assignment, location, order or notification API; B2C DRIVER-only delivery remains invariant.
- **Van:** no changed B2B assignment, visit or territory API; the existing B2B VAN-only rule remains invariant.
- **AR/EN, RTL/LTR, responsive/a11y:** role names covered in AR/EN; actual Dashboard browser/responsive/a11y verification is **not certified** by this PR. Owner #2503 defers manual UAT to V2 final, but **does not waive missing executable source requirements**.

## Delivery and security rules

One Issue → original branch `test/2691-v2-warehouse-manager-authority` → one PR with base `Foodex_V2`. Require Repository Policy, V2 Isolation and terminal Required CI on the exact current PR head. Keep PR Draft and Issue OPEN until all functional source requirements and independent prerequisites are satisfied. No `main` merge/push, tag, release, staging/production deployment, account mutation, credentials or cPanel action.

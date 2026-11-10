# V2 T037R04 — user, role and store navigation/HTTP matrix

- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, native table row `T037R04`.
- Issue: #2790; parent #2503. Implementation branch: `test/2790-v2-user-role-store-http-matrix`.
- **Integration: `Foodex_V2` only.** No main merge, release, tag, production deploy or production-account modification.

## Scope and authority

Exercise the existing server-backed `AdminNavigation::for()/groupsFor()` projection against **real authenticated HTTP requests**. Do not infer authorization from a visible/hidden Sidebar link. The existing `B2B_ADMIN` global role provides the wholesale product workspace; a scoped `RETAIL_INVENTORY` role is valid only for its assigned B2C store. A mixed-role actor may reach both authorized channels, but may not inherit access to a second retail store. This is a test-only acceptance matrix; it creates no new permission semantics.

| Actor / effective roles | Sidebar channels | B2B products HTTP | B2C inventory store A | B2C inventory store B | Retail link target |
| --- | --- | --- | --- | --- | --- |
| Wholesale: global B2B_ADMIN | B2B | 200 | Denied 403/404 | Denied 403/404 | Absent |
| Retail A: store A RETAIL_INVENTORY | B2C | 403 | 200 | 403/404 | Store A |
| Retail B: store B RETAIL_INVENTORY | B2C | 403 | 403/404 | 200 | Store B |
| Mixed: global B2B_ADMIN + store A RETAIL_INVENTORY | B2B + B2C | 200 | 200 | 403/404 | Store A |

Run the entire matrix in Arabic RTL and English LTR. Every permitted Retail URL must resolve to the intended `storeId` with `supportAccess=false`; a forged foreign-store direct request must not return success or redirect into a privileged workspace. Unauthorized platform-module/role-management links remain absent. For non-authorized retail destinations, either HTTP 403 (policy denial) or 404 (foreign tenant existence masking) is a safe outcome; no 2xx or redirect is accepted.

## Source-to-route/guard matrix

| Entry point | Route | Effective authority | Tenant/channel/scope |
| --- | --- | --- | --- |
| Wholesale catalog/products | `admin.b2b.module` (`products`) | Seeded B2B_ADMIN + canonical catalog permission | Wholesale B2B only |
| Retail inventory workspace | `admin.b2c.module` (`inventory`, `store_id`) | Seeded RETAIL_INVENTORY + direct HTTP store membership | Specific assigned B2C store |
| Navigation projection | `AdminNavigation::for()/groupsFor()` | Same backend role/permission/channel/store checks; not a grant | Current authenticated user |
| Platform/role management (negative) | `platform_owner_modules`, `sidebar_role_access` | No privileged permission in matrix actors | Hidden and not elevated |

This QA addition intentionally does **not** change financial maker/checker, credit authorization, audit writes, entitlements, readiness or dispatch. Those gates are independent of T037R04's navigation test; no claim is made that this matrix covers their product acceptance. Existing role-changing/audit tests remain authoritative for mutations. It does not generate or alter live users.

## App impact and accessibility

| Surface | Classification and evidence |
| --- | --- |
| Laravel backend | New database-backed feature regression test; no production route, model, permission, schema or API change |
| Dashboard | Existing AR/EN Sidebar keys, B2B/B2C channel projection, LTR/RTL HTTP-rendered wholesale workspace and direct store routes tested; no new UI component |
| Customer app | Not affected: no API/OpenAPI, auth payload, Flutter source, navigation or presentation change |
| Driver app | Not affected: no dispatch, driver API, Flutter source, navigation or payload change |
| Van app | Not affected: no wholesale fulfillment/van API, Flutter source, navigation or payload change |

There are no affected mobile-app contracts requiring a new mobile issue. Static HTTP/locale assertions do not replace browser viewport/keyboard accessibility acceptance; that belongs to P145/#2867 and must not be claimed complete here.

## Validation and acceptance

Run from `backend/`:

```bash
php artisan test --filter=V2UserRoleStoreHttpMatrixTest
vendor/bin/pint --test tests/Feature/V2UserRoleStoreHttpMatrixTest.php
```

Then verify latest-head Repository Policy, V2 Isolation Gate, Required CI Gate, backend test/security/localization jobs, and exact PR base `Foodex_V2`. Record the real checks and merge commit in Issue #2790 before closure. If any exact-head check fails, fix the same branch and PR. Do not close the Issue on documentation alone.

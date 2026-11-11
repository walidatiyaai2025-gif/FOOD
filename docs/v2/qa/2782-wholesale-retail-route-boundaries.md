# FOODEX V2 #2782 — P031 Wholesale / Retail HTTP tenant isolation

**Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, paragraph `P031` / parent [#2503](https://github.com/walidatiyaai2025-gif/FOOD/issues/2503). **Target:** `Foodex_V2` only. No deployment, production checks or `main` promotion.

## Existing canonical authority

The Dashboard uses the existing `B2bWorkspaceController` and `B2cWorkspaceController` for channel-specific endpoints. `TenantContextResolver::wholesale` requires an active allowed **global** role; `retailStoreIds` derives **active** store-role assignments joined to active B2C stores. `retail` enforces matching B2C store/channel and requires an explicit, audited `support_access` action for Super Admin. The Sidebar is a permission-filtered projection; it must never decide access by itself.

**Placement:** Reuse current B2B/B2C workspaces, no extra screen, route, navigation group, data field, migration or permission writer. The selected code adds **only** issue-scoped authenticated HTTP regressions.

## Contract matrix (synthetic seeded fixtures; never real user data)

| Actor | HTTP route | Expected response | Invariant |
| --- | --- | --- | --- |
| B2B_ADMIN global | `admin.b2b.dashboard` | 200 | Authorized Wholesale |
| B2B_ADMIN global | `admin.b2c.dashboard` / `admin.b2c.module` with forged Retail store | 403 | No Wholesale → Retail pivot |
| B2C_STORE_ADMIN assigned Retail A | `admin.b2b.dashboard` / `admin.b2b.module` with forged Wholesale store | 403 | No Retail → Wholesale pivot |
| B2C_STORE_ADMIN Retail A | `admin.b2c.module?module=inventory&store_id=A` | 200 | Authorized own-store view |
| B2C_STORE_ADMIN Retail A | Same with Retail B or Wholesale store ID | 404 | Other store hidden, channel mismatch rejected |
| SUPER_ADMIN | Explicit Retail store without `support_access=1` | 403 | Platform identity alone cannot enter Retail support context |
| SUPER_ADMIN | Retail store with `support_access=1` | 200 + audit log | Explicit support action independently authorized/audited |
| Roleless active user | Both channel dashboards | 403 | No implicit access merely through a route name |

The canonical `/admin/b2c/orders` URI is explicitly registered earlier as `admin.b2c.orders.canonical` and dispatched to `OrderOperationsController::b2cIndex`, even when URL generation uses `admin.b2c.module` with `module=orders`. That operations center implements its own role/channel/store policy. It must not be mistaken for `B2cWorkspaceController::show` when verifying Retail support opt-in. The B2C workspace HTTP tests therefore use `module=inventory`, which resolves to the actual workspace controller. A future P031 route inventory must review **both** route families independently.

The tests exercise both **Arabic RTL** and **English LTR**, verify persistence of the support audit event and do not modify role grants, store assignments after fixture setup, records, or any accounting balance. Cross-tenant 404 assertions distinguish disclosure from denial.

## Customer / Driver / Van impact review

- **Customer:** no new or altered `/api/v1` payload, login, customer store context, invoice, pricing, dispatch, notification or Flutter code. Dashboard web endpoint test only.
- **Driver:** no Driver Flutter or driver assignment/action API change; B2C DRIVER-only operational dispatch remains intact.
- **Van:** no Van Flutter or van visit/assignment API change; B2B VAN-only dispatch remains intact.

No additional app implementation issue is needed for this test-only change. Existing mobile contract and dispatch tests remain required for the final V2 acceptance gate.

## Verification and remaining system-wide acceptance

Run `php artisan test --filter=V2WholesaleRetailRouteBoundaryTest`, `vendor/bin/pint --test tests/Feature/V2WholesaleRetailRouteBoundaryTest.php`, PHP syntax/static checks and exact-head GitHub Required CI, including MySQL/Redis fresh-install/upgrades.

**Scope limitation:** This PR covers the named B2B/B2C Dashboard workspace route family, not *every* API, finance mutation, warehouse, support route or mobile endpoint implied by the umbrella-like phrase "في كل Route." #2782 must **remain OPEN** until a separately evidenced, exhaustive route inventory/negative matrix and cross-app integration acceptance is complete, even if this PR merges with green CI. No production identity/DB audit is claimed. A green PR alone does not certify all channel/tenant isolation across the entire system.

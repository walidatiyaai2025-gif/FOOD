# V2 T019R05 — store1 / store2 authorization audit (Issue #2771)

## Scope and evidence boundary

- Source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, T019R05; parent #2503.
- Destination: `Foodex_V2` only. No `main`, release, deployment, production account mutation or historical user data in this PR.
- The source document refers to a user called `store1` and a restriction to store 2. **The label alone is not an authorization identity or grant.** A historical exported account must not be mutated or inferred from the nickname.
- This PR proves the existing, live-code *authorization behavior* using isolated synthetic accounts. It does **not** claim to have inspected the archived real user's role records, current identity, store assignments, overrides, production session, tenant or entitlement. That distinct read-only verification is still an acceptance prerequisite.

## Canonical route and decision

| Surface | Actor | Scope | Expected decision |
| --- | --- | --- | --- |
| `GET /api/v1/admin/inventory` | active synthetic user displayed as `store1`, `RETAIL_INVENTORY` assigned only to store 2 | Retail inventory | 200, store-2 row only (AR/EN) |
| `POST /api/v1/admin/inventory/{store1-inventory}/adjust` | same actor | store 1 | 404, no movement or mutation (AR/EN) |
| `POST /api/v1/admin/inventory/{store2-inventory}/adjust` | same actor | store 2 | success, inventory adjusted and audit recorded |
| `GET /api/v1/admin/inventory` | `CUSTOMER_SUPPORT` with no retail store assignment | B2C inventory | 403 |
| `POST /api/v1/admin/inventory/{inventory}/adjust` | same unrelated global-role actor | either retail store | 404, no movement |

The tested path is already present in `backend/routes/api.php`. `InventoryController` resolves list visibility through `Inventory::accessibleTo()` and must enforce a per-record tenant boundary before mutating inventory. `OperationalTenantScope` is the authoritative store-permission check. The test deliberately creates distinct stores/catalogs/products/warehouses, not shared cross-store records.

## Scope classification

- **Backend/API:** test-only verification of existing protected inventory endpoints and audit persistence; no permission, controller or schema change.
- **Dashboard:** no Blade/navigation change, no new business UI. Existing consuming route and authorization logic remain unchanged; the store-restricted payload is the observable server contract.
- **Customer app:** no changed customer API or Flutter source, no customer-facing navigation or pricing behavior.
- **Driver app:** no changed driver API or Flutter source, no dispatch/delivery changes; B2C remains Driver-only.
- **Van app:** no changed van API or Flutter source, no B2B/Van dispatch behavior; B2B remains Van-only.
- **AR/EN:** both locales execute the same allow/deny HTTP assertions. No translatable strings or RTL/LTR components changed.
- **Security/SoD:** no authority inferred from user display name; cross-store inventory IDOR denied before mutation; no finance or approval changes.

## Independent acceptance still required

1. Authorized **read-only** retrieval of the exact historical T019R05 record and current role/store/tenant/override state. Keep any identity and confidential data out of public GitHub.
2. Compare the real record's effective permissions and Sidebar projection against the intended store 2 restriction. Confirm the person/account type and whether the naming mismatch is merely cosmetic or an actual misassignment.
3. If a configuration correction is required, obtain the proper approval, add separate targeted implementation evidence, and never modify a production identity from this test PR.
4. Exact-head Laravel regression, security, localization, Repository Policy, V2 Isolation and required child CI jobs must pass before merge.

**Manual owner/staging/UAT:** deferred to final V2 program by owner decision on #2503. Synthetic-only regression is not a substitute for the archived/current account audit.

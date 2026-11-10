# V2 P119 — independent B2C store-scoped RBAC

**Source**: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, requirement `P119`, parent #2503, leaf #2849.
**Integration target**: `Foodex_V2` **only**. No main, release, tag, deployment, or production access.

## Why this feature is not a global B2C permission

Retail store staff are assigned role-to-store pairs in `user_store_roles`. For a store-specific permission, `User::hasPermission($code, $storeId)` must resolve permissions only from the *requested* store's active store roles and allowed scoped overrides. The same named role in another store is not inherited; `User::hasPermission($code)` is not an aggregate of store assignments. `AdminNavigation` is a projection of this authority, not a second security layer.

| Principal / entry | Positive authority | Denied scope |
|---|---|---|
| Retail Inventory at Store A | `inventory.view` for A, B2C inventory route with `store_id=A` | Other stores' inventory, global `inventory.view`, B2B module, Security |
| Retail Inventory at Store B | `inventory.view` for B, B2C inventory route with `store_id=B` | Store A and unassigned Store C |
| Operations at A + Inventory at B (same user) | `orders.view`/reports at A; `inventory.view` at B; Sidebar URLs resolve each respective store | Inventory at A and orders at B — no permission union across stores |
| Forged Store C ALLOW with no Store C assignment | None | Still no `inventory.view` at C; direct foreign-store URL returns 404 |
| Retail user passes `support_access=1` | No promotion to platform support | Direct foreign-store request remains 404 |

## Acceptance and tests

Dedicated `backend/tests/Feature/V2IndependentRetailStoreRolesTest.php` runs fresh migrations via `RefreshDatabase` and `CoreReferenceSeeder`. The test verifies two separate role users, a third unassigned store, independent `effectivePermissionCodes`, explicit user-role/store permissions, actual Laravel HTTP guards, forged query arguments, two distinct role grants on one user, AR/EN + RTL/LTR render parity and the absence of any unauthorized override creation. This exercises the existing `B2cWorkspaceController` and `TenantContextResolver` rather than a stub of UI visibility.

A production change to canonical User/RBAC code is **not required if** these regression tests pass: guard and store-role logic already exists. Do not modify shared `User.php` while #2829 / PR #2965 owns that file. If this test reveals leakage, record the failure, coordinate ownership and repair on this single issue branch before integration.

## Component impact matrix

| Surface | Assessment | Reason |
|---|---|---|
| Backend / RBAC | Security regression coverage only | Existing role codes, assignments, tenant and route guards exercised; no API or DB schema modified |
| Dashboard | Security/navigation regression coverage only | Existing B2C inventory/reports links and real module routes verified; no visual layout changes |
| Customer Flutter | Not affected | No customer API, payload or mobile permission behavior modified |
| Driver Flutter | Not affected | No driver API, session, payload or dispatch behavior modified |
| Van Flutter | Not affected | No van API, session, payload or dispatch behavior modified |

**Fulfillment contract**: B2B → VAN ONLY; B2C → DRIVER ONLY; unchanged.

## Verification

Focused Laravel test: `cd backend && php artisan test --filter=V2IndependentRetailStoreRolesTest`. Require all exact-HEAD repository policy, AR/EN localization, V2 isolation, backend static analysis/full suite, MySQL/Redis installer-upgrade-recovery, and `required-ci-gate` child jobs to be successful. Preserve all run URLs and final SHA in Issue #2849 before marking acceptance. Source test asserts rendered language direction and actual HTTP permission guards; it is **not** evidence of a new visual design or all browser viewport sizes, since no UI layout was changed.

Do not close on Build alone. The PR must be one issue-scoped PR into `Foodex_V2` and all CI must pass against its exact last commit.

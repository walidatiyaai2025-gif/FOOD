# V2 #2911 — Table 5 data row 6 / B2B Driver legacy Van authority

## Historical source and privacy

- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, Native Table 5, **data row 6** (one inherited role permission, `B2B_DRIVER` at snapshot time); master #2503.
- This archived assignment is **not proof** of the named historical account's present type, active state, live Driver row, effective Van assignment, store scope, role changes, overrides or Sidebar projection.
- This implementation uses **synthetic Laravel users only**. No source username, PII, passwords, private tokens or production data are copied to GitHub. No real account is mutated, deleted, disabled or elevated.

## Distinct runtime defect corrected

`VanRuntimeContextResolver::canUseRuntime()` previously allowed the legacy driver compatibility fallback for **`B2B_DRIVER` OR `B2C_DRIVER`**, then checked only the selected Driver record's user ID and `is_active`. An accidentally active Van assignment referring to a Retail `b2c` Driver could therefore make a **B2C-only Driver a Van actor** with an `app:van` token. A mixed role user with `B2B_DRIVER` yet only a Retail Driver row could do the same.

The owner-locked dispatch contract is **Wholesale/B2B → Van only; Retail/B2C → Driver only**. The compatibility bridge now requires BOTH:

1. effective global `B2B_DRIVER` role on the authenticated actor; and
2. matching **active `drivers.driver_type = b2b`** row owned by that actor and referenced by a currently effective Van assignment.

The explicit `van.login` permission for authorized `VAN_OPERATOR` and its effective representative assignment remains unchanged. The prior `User.is_active` fail-closed guard and partial-user projection support from merged #2920/PR #3335 remain unchanged. Direct endpoint guards and current assignment validity still apply. No fresh credential, assignment or entitlement is fabricated.

## Tests, negative proof and app impact

New `backend/tests/Feature/V2ExportedAccountRow6VanBoundaryTest.php`:

- **Positive Wholesale**: synthetic active B2B driver + active Van assignment retains resolver eligibility, Van visits `200`, Van push registration `201`; Retail Driver push remains `403`.
- **Negative Retail, Arabic/English**: B2C driver with a deliberately accidental active Van assignment has a resolvable context but receives Van visits `403`, push registration `403`, no Van device token; legitimate B2C Driver push succeeds `201`.
- **Negative mixed roles**: a synthetic actor with both B2B/B2C roles but only an active **b2c** Driver assignment is denied Van visits and push, without clearing its historical role assignments or deleting the driver/van record.
- **Positive explicit operator**: assigned `VAN_OPERATOR` remains able to access Van runtime without requiring a Driver table row.

| Surface | Runtime impact and proof |
| --- | --- |
| Backend/API | **Affected**: only the server-side legacy Van compatibility authority, used by Van HTTP middleware, login context and push audience. No endpoint, request/response payload or client type/schema change. |
| Dashboard | **No view, route, Sidebar or controller change**. Existing RBAC/user audit surfaces stay authoritative. A live user type is not inferred from the source role snapshot. |
| Customer | **No impact**: no Customer API, role, checkout, storefront, notification recipient or Flutter change. |
| Driver | **Behavior preserved**: Retail B2C Driver retains native Driver access; B2B legacy driver still cannot borrow B2C Driver privileges. Cross-app contract issue #2740, Driver scope #2735. |
| Van | **Affected by stricter authorization**: B2C-only Driver cannot borrow Van runtime by stale/accidental assignment; genuine B2B legacy-driver + explicit Van Operator positive coverage. Related Van access issue #2738. No Flutter payload or UI change required. |

AR/EN tests exercise both locales; no new UI text or translations, so no RTL/LTR presentation changes. Any failed stale-session API call remains an authorized 403 from existing guards.

## Completion boundary

This PR is a **bounded, executable security fix**, not a complete historical account audit. The separate, **authorized confidential read-only** source-to-current review must still classify the specific historic row 6 account across Dashboard/Customer/Driver/Van, active status, actual role/permission/override history, global vs store assignments, tenant scope and Sidebar projection, without sharing identifying data in GitHub. If a legitimate live role/assignment discrepancy is found, implement correction only through a reviewed and authorized V2 change, never by silently modifying the production user.

A safe **technical slice** can be merged only into `Foodex_V2` after exact HEAD Repository Policy, V2 Isolation, Required CI and affected Laravel full suite/MySQL acceptance all succeed. Keep **#2911 OPEN** until the missing account-specific source acceptance has a provable disposition. Master #2503 remains open until all program tests/UAT; no main, deployment, ZIP release or tags.

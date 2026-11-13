# V2 #3344 — disabled account effective-permission projection

**Parent:** #2503. **Related source:** #2664 (archive/disable entry), #2806 (User action menu), #2810 (protected offboarding), and `docs/v2/V2_ISOLATION_GOVERNANCE.md`. **Integration only:** `Foodex_V2`.

## Verified behavior and exact decision

Before this change, `User::hasPermission()` returned false immediately when `is_active=false`; `OperationalTenantScope::allowedStoreIds()` likewise rejected inactive users. However `User::effectivePermissionCodes()` still reported inherited global permissions, store-role grants, legacy direct ALLOW overrides, or the entire Super Admin permission catalog. This inconsistency could misstate disabled-account authority in admin/user profile, security/assistant context and identity/API permission projections. It was a **reported permission-list mismatch**, not proof that a disabled principal could execute an otherwise protected action.

The single authoritative correction is an early inactive-account guard in `User::effectivePermissionCodes()` returning `[]`, **before** the Super Admin shortcut and role/override enumeration. `inheritedPermissionCodes()` remains intentionally historical for audit. `hasPermission()` and tenant-scoped enforcement already deny a disabled principal and are unchanged. Re-activation legitimately restores the previously assigned role/override-derived *effective* projection; no roles, permissions, tokens or audit records are deleted or granted by the fix.

## Authorization and evidence matrix

| Principal / context | Active expected | Deactivated expected | Stored audit history |
| --- | --- | --- | --- |
| Global Super Admin | existing privileged permission catalog | `effectivePermissionCodes()=[]`; `hasPermission()=false` | Role pivot retained; inherited catalog remains inspectable |
| Customer-service global role + historical `orders.manage` ALLOW | existing active role/allow-derived permission list | `effectivePermissionCodes()=[]`; existing HTTP auth middleware denies `/api/v1/orders` with 401 in AR and EN | Role, override and active-status row retained |
| Assigned Retail store administrator | `catalog.manage` only within assigned B2C store | global + assigned/foreign store effective lists `[]`; tenant scope `[]` | Store role retained, foreign-store exclusion preserved |
| Reactivated subject | Existing scoped permissions restored from persisted roles | Not applicable | Original assignments preserved; no elevated grant |

The regression file `backend/tests/Feature/V2InactiveEffectivePermissionProjectionTest.php` covers synthetic Super Admin, B2C store role, legacy ALLOW, deactivation, permissions/no-mutation, reversible reactivation, direct API denial and AR/EN. Source file `backend/app/Models/User.php` is the only production code change; no new controller, duplicate guard, route, Sidebar entry, schema or migration.

## Reuse → Extend → Compose → New Screen and app effects

- **Placement:** No new UI. Reuse existing Security Center / account profile projections; this is a backend-only security consistency fix.
- **Backend/API:** `effectivePermissionCodes()` is also used by existing API principal/profile contexts; the correction applies exclusively to deactivated identities that already lack authorized API access. Existing guarded routes and successful active-account contract remain unchanged. A disabled user gets a denied response, not a newly enabled API action.
- **Dashboard:** Existing Users/Security Center projections become consistent with effective policy and tenant-scoped access. No navigation or visual changes, and no new translations.
- **Customer:** No Flutter, API route, pricing, order, guest or valid active-account contract change; disabled user cannot make an authorized request. Existing `/api/v1/orders` revoked-identity negative test included.
- **Driver:** No Flutter, dispatch, B2C driver role, assignment or valid API behavior changes; disabled accounts have no authenticated ability.
- **Van:** No Flutter, B2B territory/Van assignment, inventory or valid API behavior changes. B2B = Van only, B2C = Driver only unchanged.
- **AR/EN:** Same denied inactive-account result for both locales. No UI strings/keys introduced, so RTL/LTR rendering is unchanged.
- **Data/security:** No live users inspected/modified, no role/override deletion, no new exception to authenticated tenant isolation, finance SoD, maker/checker, or storage/backup behavior.

## Acceptance

Pass full backend feature regression and exact-head Repository Policy, V2 Isolation Gate and Required CI (Laravel, PHP syntax/Pint/PHPStan/Composer audit/OpenAPI, AR/EN, MySQL/Redis fresh-install/upgrade/recovery where applicable). One Issue #3344, branch `fix/3344-v2-inactive-effective-permission-projection`, one PR into `Foodex_V2`. Close this narrowly scoped issue **only** when CI and behavior are verified after integration. No main merge, release, tag, cPanel install, or production deployment.

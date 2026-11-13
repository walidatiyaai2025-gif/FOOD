# V2 #2913 — private historic row 8 / B2B driver boundary audit

**Source:** 2026-11-09 FOODEX unified RBAC/ERP/Sidebar discussion, native DOCX Table 5, data row 8. **Integration:** `Foodex_V2` only; parent #2503. No live account identifiers, secrets or exported identity data belong in this repository.

## Historical fact vs current authority

The archived, not-live export classifies the row as `B2B_DRIVER` with one distinct inherited permission. It does **not** establish the identity's current roles, status, scoped role pivots, authorization history or real app login. Do not delete, reassign or activate real accounts based on this snapshot.

Current canonical `config/job_roles.php` and `config/permissions.php` define `B2B_DRIVER` as a global, legacy field-operations operator with only `deliveries.b2b.execute`. `VAN_OPERATOR` separately owns `van.login`, while `B2C_DRIVER` owns `deliveries.b2c.execute`. The historical B2B role is excluded from the Dashboard's authorized Wholesale and Retail global-role lists, and cannot gain store-scoped authority just from its global grant. B2B fulfillment remains **Van only**, B2C fulfillment **Driver only**.

## Same-issue Sidebar projection correction

The audit reproduced a distinct navigation-authority mismatch: `AdminNavigation::canAccessPlatformErp()` accepted **any** active global role, allowing the historical `B2B_DRIVER` (and other mobile-only roles) to project ERP Center/My Work links even without Dashboard/Wholesale/Retail authorization. The minimal production-code fix keeps all existing business global-role behavior and filters only `B2B_DRIVER`, `B2C_DRIVER`, `VAN_OPERATOR` from that ERP navigation shortcut. This does not change any HTTP authorization, role/pivot, mobile token, Van assignment or app runtime. The focused test asserts no Sidebar links for this synthetic mobile-only principal.

## Executable, synthetic test slice

New `backend/tests/Feature/V2ExportedRow8B2bDriverScopeAuditTest.php` covers:

- Built-in seeded role/single-permission matrix, no implied Van, Retail Driver, Security or Finance grant.
- AR/EN role presentation; neither locale yields an authorized Dashboard/Sidebar or Wholesale/Retail store-scoped operational tenant.
- Negative direct Dashboard Security/Wholesale/Retail HTTP; persisted synthetic role assignment is preserved.
- Van and Retail Driver HTTP runtime denial even with both mobile token scopes; a token alone does not supply Van assignment or a B2C Driver role and active driver record.

Run: `cd backend && php artisan test --filter=V2ExportedRow8B2bDriverScopeAuditTest`. Also require full exact-head PHP syntax/Pint/PHPStan/security/AR-EN/backend/MySQL-Redis CI, Repository Policy and V2 Isolation.

## App / security impact matrix

- **Backend:** minimal `AdminNavigation::canAccessPlatformErp` read-only authorization projection fix plus synthetic authorization regression. Existing permissions, role assignments, tenant rules, middleware and data remain untouched.
- **Dashboard:** existing Sidebar hides ERP Center/My Work for mobile-only roles, matching direct HTTP denial. No new route/screen; AR/EN unchanged.
- **Customer:** no API payload, login or Flutter change.
- **Driver:** no Flutter/API change; legacy B2B role is **not** an authorized B2C Driver runtime principal.
- **Van:** no Flutter/API change; Van token permission and active assignment remain independently required.

## Remaining acceptance / prohibited claims

This scoped code/test slice is **not** authorized current-user evidence. #2913 must remain OPEN until an appropriately authorized, least-privilege private review reconciles the actual historical account to current status, role pivots/overrides, store/channel scopes and audit trail, and records a sanitized acceptance decision. No real account update is approved or performed. No main merge, release, tag, APK publication, cPanel or production action. Parent #2503 stays OPEN.

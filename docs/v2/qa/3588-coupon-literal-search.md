# V2 #3588 — Literal search in scoped Coupon management

**Parent:** #2503 / P082. **Branch target:** `Foodex_V2` exclusively. No main, release, tag, staging deployment, cPanel or production.

## Discrepancy and fix

`CouponManagementController::index` previously passed raw user input as the substring `LIKE` pattern on `code`, `name_ar`, `name_en`. An authorized manager typing a literal `%` or `_` saw unrelated coupons because these characters are SQL wildcards. Values were already parameter-bound; this is a **read accuracy** defect, not a proven authorization or injection bypass.

Apply a single literal pattern: escape `!` → `!!`, `%` → `!%`, and `_` → `!_`; then wrap with intentional `%` and query fixed columns using bound `LIKE ? ESCAPE '!'`. Preserves SQLite/MySQL/MariaDB semantics.

## Placement, roles, route authority and behavior

- **Domain:** Catalog & Pricing / Promotions; **entity:** Marketing Coupon; **role:** authorized B2B / B2C admin and Super Admin; **goal:** find existing coupon by code or bilingual title.
- **Workflow / placement:** REUSE canonical `GET /admin/coupons` Data Explorer, its already authorized navigation and grid. `navigation_type=SIDEBAR` through the canonical Catalog/Promotions destination; no additional page, custom lookup, raw ID entry or UI primitive. Search is legitimate free text. Desktop, tablet, mobile-web 320–430px, accessibility, AR/EN and RTL/LTR unchanged.
- **Guardrails:** existing `authorizeAccess('coupons.view')`, `visibleCoupons` and `OperationalTenantScope` remain authoritative, including store/global DENY precedence and platform/wholesale/retail separation. Mutation-specific `coupons.manage` and audit unchanged.
- **State/filter preservation:** channel B2B/B2C, active/inactive status, most-recent-first, 25 records/page, query-string preservation and every existing create/update/toggle/deactivate/delete/redemption path unchanged.

## HTTP regression matrix

New `backend/tests/Feature/V2CouponLiteralSearchTest.php` uses real authenticated `RefreshDatabase` requests and seeded canonical roles:
1. Super Admin literal `%_!`, individual `%`/`_`/`!` over code/Arabic/English names, negative wildcard lookalike, Arabic/English and ordinary code match; no audit mutation.
2. Retail-scoped admin: own-store authorized B2C results, foreign-store exclusion, channel B2B isolation and active/inactive filters; Super Admin comparison.
3. Unprivileged signed-in user direct GET is forbidden (HTTP 403).

**Impact per app:** Backend+Dashboard HTML search filtering only. Customer has unchanged coupon public API/checkout and Flutter; Driver no changed route/Flutter/collection; Van no changed route/Flutter/stock; B2B VAN ONLY / B2C DRIVER ONLY preserved.

## Delivery / acceptance evidence

Source files: `backend/app/Http/Controllers/Admin/CouponManagementController.php` list search only; new test; this QA note. Require exact-final-head PHP syntax, Pint, PHPStan, full Laravel, security/OpenAPI, AR/EN localization, required MySQL/Redis deployment checks, Repository Policy, FOODEX V2 Isolation and terminal Required CI. The success of an older commit or partial check does not authorize merge. Manual/live UAT deferred to master #2503; no publication/production claim. Close this atomic issue only after final reviewed PR is SHA-guard merged into `Foodex_V2`, and document verified CI and integration SHA.

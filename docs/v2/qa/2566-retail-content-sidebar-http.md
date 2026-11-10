# V2 #2566 — P236 Content Sidebar and real Retail HTTP authority

**Source:** FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, paragraph P236; parent #2503. **Integration branch:** `Foodex_V2` ONLY. Do not promote to `main`, release, tag or production.

## Product placement and navigation

- **Business domain / entity:** Marketing, Retail content, B2C storefront banner display/targets.
- **Actor and purpose:** An authorized Retail store manager opens its own content workspace from the main Dashboard navigation to inspect content and product/category destination targets without knowing a URL or internal store ID.
- **Placement:** **Reuse** the existing `b2c_content` item in the `marketing` group, existing `admin.b2c.module` with `module=content`, and existing `B2cWorkspaceController::contentModuleData` plus shared `admin.b2c-workspace` Blade. Do not create a duplicate Content page, Sidebar group, permission or editor.
- **Permission / channel:** `promotions.view` in the active assigned B2C `RETAIL_STORE` context for reading. The existing editor exposes state-changing banner controls only with `promotions.manage`. Role, store, channel and business profile are independently enforced by Laravel; Sidebar visibility does not grant access.
- **Navigation owner / entry:** Catalogued in Marketing → Content (`SIDEBAR`) by the canonical registry. The effective authorized page may be a nested child or be placed in an administrator-configured group; discovery checks must traverse the actual visible Sidebar tree rather than assuming every leaf is a direct group child. Parent/fallback: existing Retail Dashboard and the configured domain parent; exact route carries `store_id` chosen from a permitted Retail store, not a guessed or foreign ID.
- **Module readiness:** Existing Retail Content is a supported workspace module for business profile `RETAIL_STORE`. It is not a valid `RESTAURANT` profile module; no implicit ERP enablement/entitlement or false ready state is introduced.
- **UI:** Existing FOODEX shell, page content and adaptive mobile web/tab treatment remain unchanged. Existing AR/EN labels, RTL/LTR, keyboard and focus behavior are preserved rather than copied into an independent component. Final 320–1280px manual browser/a11y acceptance remains on master #2503/#2750.

## Existing data and security contract

`B2cWorkspaceController::contentModuleData` selects active B2C products/categories only from the resolved authorized store's catalog, excluding migration quarantine. It presents draft-first storefront banner content via `StorefrontDraftEditorService::viewModel`. These reads must not publish a new revision or alter orders/finance. Cross-store `store_id` requests are deliberately answered with **404**, because the server does not disclose stores outside the actor's assigned scope. A user without any Retail store context is **403**. An inactive user is denied at the Dashboard entry middleware.

## Regression evidence

New `backend/tests/Feature/V2RetailContentSidebarHttpTest.php` asserts:

1. Active Retail `B2C_STORE_ADMIN` discovers `b2c_content` through the effective Sidebar tree (direct item or configured child), with canonical Marketing registration, exact route, permission, localization key and assigned store; real page returns 200 in Arabic/RTL and English/LTR.
2. Own-store Content returns 200; foreign B2C store returns 404 without exposing the other store through the response. Product data count is preserved; reading can initialize a draft-first published storefront revision, but must never delete existing revisions.
3. Roleless, inactive and Wholesale-only actors have no Retail Content link and cannot open the route (403).
4. A `RESTAURANT_DIGITAL_MANAGER` does not receive a Retail Content link and cannot open that profile-excluded module (403 or 404 depending on profile/store context).

The existing `RetailAdminCatalogAuthorizationTest`, `AdminNavigationAuthorizationAuditTest`, storefront draft/publish tests and controller authorization tests cover adjacent product/editor semantics; this issue does not duplicate that work. No executable UI, API, workflow or permission changes were necessary based on the inspected canonical source.

## Impact classification and isolation

| Surface | Classification |
| --- | --- |
| Laravel Backend | Isolated new feature regression on actual protected route; no API/controller/schema or production mutation |
| Management Dashboard | Reuses `marketing / b2c_content`, existing responsive page and effective `promotions.view` checks |
| Customer Flutter | No impact: API/storefront payload, content publication, product details and customer navigation unchanged |
| Driver Flutter | No impact: no route, wallet, collection or delivery status integration |
| Van Flutter | No impact: no route/van work, wholesale catalog or consignment integration |

Wholesale fulfillment remains **VAN ONLY**, Retail fulfillment remains **DRIVER ONLY**. Cross-store and cross-channel rights are not expanded. Sensitive financial SoD remains unchanged.

## CI and closure conditions

Own exactly two NEW files: `backend/tests/Feature/V2RetailContentSidebarHttpTest.php` and this QA matrix. Focused command in a suitable PHP/Laravel environment: `cd backend && php artisan test tests/Feature/V2RetailContentSidebarHttpTest.php`. Accept completion only after the exact final PR HEAD passes Repository Policy, FOODEX V2 Isolation, Laravel/Pint/PHPStan, AR/EN, security, applicable MySQL/Redis install/upgrade/recovery and terminal `required-ci-gate`; then merge into `Foodex_V2`, record the SHA and close #2566 only if all code acceptance is satisfied.

`MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` by owner's latest parent #2503 decision. No UAT, installation, production/SSH or mobile artifact publication claimed.

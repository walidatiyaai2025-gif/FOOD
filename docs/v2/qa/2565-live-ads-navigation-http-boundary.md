# V2 #2565 — P235 Live Advertising: Sidebar, HTTP and Customer Feed

**Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, Sidebar P235 (Catalog, Promotions and Advertising), master #2503.

## Placement / navigation authority

**Reuse** the canonical `AdminNavigation` Advertising business group → `live_ads` entry → `admin.live-ads.index` (`GET /admin/live-ads`). No second Sidebar link, page, workflow, policy, model, API endpoint, or independent presentation authorization.

- Domain: Advertising and Promotions.
- Entity: `LiveAd` (published content/CTA, image, frequency, scheduling).
- Actor: global `B2B_ADMIN` / assigned `B2C_STORE_ADMIN` / `SUPER_ADMIN` under existing hard platform constraints.
- Navigation type: `SIDEBAR` and existing management page; the B2C-only store feature switch is `stores.live_ads_enabled`.
- Entry point: `AdminNavigation::routeItemAdvertising`; permissions `live_ads.view` and `live_ads.manage`, actual HTTP protected by `management.dashboard` and `LiveAdController::authorizeAccess/authorizeAd`.
- Ownership/scope: wholesale B2B global authorization sees only B2B rows; retail B2C assignment sees only records of an assigned enabled store, cannot mutate foreign store.
- Destination: no extra ERP module entitlement/operational readiness is needed for this existing advertising service; **retail feature enablement** and active role are actual authorities. A disabled store is not allowed to view/mutate through a hidden route.
- Existing page follows AR/EN and RTL/LTR, responsive `@media(max-width:900px)` single-column layout; final device/browser a11y acceptance is not claimed here.
- Field types: `channel`, `frequency`, status and CTA destination are enums/managed choices; store is a scoped lookup; bilingual titles and body are legitimate free-text; image is upload with existing image service.

## Executable acceptance

The dedicated Laravel `backend/tests/Feature/V2LiveAdsNavigationHttpBoundaryTest.php` validates:

| Role/state | Navigation and actual protected HTTP | Rows/actions |
| --- | --- | --- |
| B2B_ADMIN global, AR/EN | Advertising → Live Ads, `GET /admin/live-ads` 200, RTL/LTR | Shows own wholesale record, does not show retail stores |
| B2C_STORE_ADMIN assigned A, AR/EN | Same authorized entry and 200 | Shows store A only, not wholesale or other store B |
| Authorized Retail A manager | `PATCH /admin/live-ads/{id}/toggle` own 302, foreign 404 | Audit `live_ad.status_changed`, foreign record unchanged |
| Same Retail A with `live_ads_enabled = false` | Link absent, direct GET and PATCH 403 | Public Customer feed for disabled store 404 |
| B2B_ADMIN with explicit global per-user DENY and forced-visible role layout | Entry absent and direct GET 403 | Visual override does not grant `live_ads.view` |
| Inactive B2B role | No authorized item and direct GET 403 | No grant from inactive role |

The existing `EngagementOpsIsolationTest` additionally covers live-ad time windows, active status, cross-channel API scope, localization, disabled store and user/device boundaries. The new contract verifies the **existing** `/api/v1/live-ads?channel=b2c&store_id=A&locale=ar|en` response returns only the actual A ad and localized title, without modifying the API.

## Backend / Dashboard / mobile impact matrix

| Surface | Classification | Reason |
| --- | --- | --- |
| Laravel Backend | Automated Feature regression only | Existing controller/model/permissions/middleware and public API code untouched |
| Dashboard | Existing Sidebar and page only | No Blade/CSS/JS/new navigation or live behavior changes |
| Customer Flutter | **Not affected (API contract verified)** | Existing `/api/v1/live-ads` used by Customer remains unchanged in routing, JSON schema, filtering, CTA and locale |
| Driver Flutter | **Not affected** | No Driver UI/API, B2C DRIVER ONLY dispatch, assignment, GPS or delivery changes |
| Van Flutter | **Not affected** | No Van UI/API, B2B VAN ONLY order/route/visit/collections changes |

## Delivery

One issue-scoped branch / PR targeting **only `Foodex_V2`**. Verify all applicable exact-head Repository Policy, V2 Isolation, Required CI (PHP syntax, Pint, PHPStan, Composer audit, OpenAPI, Arabic/English, Laravel Backend tests, MySQL/Redis fresh-install, upgrade and recovery, terminal `required-ci-gate`). Do not report skipped Flutter jobs as successful mobile validation.

**MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL** under explicit owner update #2503. Real-browser responsive/a11y and consolidated device acceptance remain with #2503, #2750 and #2749; no claim of live hosting validation or production readiness. No `main` changes, releases, tags, packages, staging publication or production deployment.

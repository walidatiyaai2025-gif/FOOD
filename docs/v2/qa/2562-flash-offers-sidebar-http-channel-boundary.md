# FOODEX V2 — #2562 / Sidebar P232: Offers Navigation and HTTP Authority

**Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P232 (Offers), parent program #2503. **Integration branch:** `Foodex_V2` only.

## Placement: reuse existing business workflows

The issue's **العروض / Offers** intent already resolves to two distinct, operational routes rather than a duplicate top-level page:

| Business capability | Navigation entry | Canonical route and context | Effective authority |
| --- | --- | --- | --- |
| Wholesale Flash Offers | `marketing → commercial_flash_offers` | `admin.commercial.wholesale-flash-offers` with a B2B store context | `promotions.view` and `promotions.manage` from a valid wholesale/global role; `OperationalTenantScope` B2B store gate |
| Retail Offers / Promotions | `marketing → b2c_promotions`, the authorized Retail module | `admin.b2c.module` (`module=promotions`, assigned B2C `store_id`), linking to `admin.commercial.flash-offers` | Store-scoped `promotions.view` and `promotions.manage`; `OperationalTenantScope` B2C store gate |

**Reuse → Extend → Compose → New Screen:** Reuse wins; `CommercialDashboardController` already renders functional management, edits, preview, analytics and Flash Offer actions; the authorized Retail Workspace already supplies the discoverable entry. Duplicating it as a new Sidebar destination would create two authorities and risk fake permission grants.

**Domain** Commercial / Marketing; **entity** Flash Offer; **personas** Wholesale B2B Admin and Retail B2C Store Admin; **goal** list/create/activate/pause and inspect offers for an authorized store; **frequency** recurring operational; **pattern** existing page/form/workbench; **fallback** existing Commercial or Retail Workspace. Actual HTTP scope is always validated server-side, not inferred from which menu is visible.

## Automated authority matrix

`backend/tests/Feature/V2FlashOffersSidebarHttpChannelBoundaryTest.php` confirms both Arabic/English and RTL/LTR:

- B2B Admin discovers `commercial_flash_offers` and follows the real authorized B2B GET into an existing Flash Offer editor.
- Retail Store Admin discovers the `b2c_promotions` workspace with an exact scoped store ID, follows the existing authorized workspace page, and opens the canonical Retail Flash Offers page. It does **not** receive the wholesale Sidebar item.
- Retail actor cannot open the B2B route or POST wholesale Flash Offers, nor POST via a wholesale URL to its own retail store.
- Wholesale actor cannot open a retail store using the B2B route or post to the B2C route using a wholesale store. A retail actor cannot POST for another retail store. Denied/cross-tenant requests return 404 (intentional anti-enumeration semantics of `OperationalTenantScope::assertStore`), before payload validation, without any `flash_offers` writes or action audit.
- An explicit per-user global **DENY** for `promotions.view` suppresses the wholesale Sidebar entry, even if a role's saved presentation setting forces it visible. A direct GET with the wholesale store is denied by the same canonical tenant authority (404). Inactive role also loses the effective route and Sidebar.
- No module entitlement or ERP readiness is invented: this is the existing Commercial Flash Offers service, not an unapproved ERP-only module. Existing feature flags and flash safety gates remain backend-authoritative.

**Already-tested complementary business behavior:** `CommercialDashboardContractTest` covers functional creation/edit/preview/analytics, bilingual direction, canonical backend contract, valid selling units and product authorization, and crucially the B2B Flash Offer **mandatory Van channel**: publishing an offer with only the Customer visibility channel is rejected. The Customer Offers page is optional display, not a wholesale checkout or DRIVER fallback.

## Individual surface impact

| Surface | Classification | Evidence |
| --- | --- | --- |
| Laravel Backend | Added executable feature regression; existing runtime unchanged | No controller, middleware, model, database migrations, permission definitions or REST API changed. Authoritative tenant and channel checks executed through HTTP |
| Dashboard | Existing working marketing and Retail Workspace pages reused | AR/EN / RTL/LTR and authorized navigation/HTTP status tested; existing responsive layout and accessible UI preserved without markup changes |
| Customer Flutter | **Not affected** | No Customer app source or API request/response, authentication, public offers or checkout contract modified; existing optional B2B display and B2C targeting untouched |
| Driver Flutter | **Not affected** | No Driver UI, B2C Driver dispatch or delivery/order events changed; no B2B Driver fallback enabled |
| Van Flutter | **Not affected** | No Van UI, B2B Van-only fulfillment, routes, collections, offers API or visit lifecycle changes; existing mandatory Van offer channel regression remains covered |

The category of work is **test+QA docs only**. Skipped Flutter CI jobs are not evidence of mobile build pass; the source-based non-impact classification applies.

## Gate and final acceptance

One issue-scoped branch and one PR **to `Foodex_V2` only**. Verify latest exact-head Repository Policy, FOODEX V2 Isolation Gate, FOODEX Required CI including Laravel full tests, PHP syntax/Pint/PHPStan/Composer security audit/OpenAPI, AR/EN localization and real MySQL/Redis fresh-install/upgrade/recovery. Require the final `required-ci-gate` SUCCESS before merge. Do not conflate green build with production readiness.

**MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL** under owner's #2503 comment. Physical responsive browser/a11y/UAT and whole-program review belong to #2503 / UX #2750 and remain pending. No `main` merge, release, tags, live cPanel installation, deployment or APK promotion under this issue.

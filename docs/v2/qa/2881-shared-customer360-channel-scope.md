# V2 P171 — Shared Customer 360 name with channel-/store-authorized data

- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` / paragraph `P171`; Issue **#2881**, parent #2503.
- Isolated integration: **`Foodex_V2` only**. One issue-owned branch `test/2881-v2-shared-customer360-channel-scope` and one PR. No main PR, release, tag or production deployment.

## Existing product behavior being locked down

**Reuse**, rather than creating a second page: `Accounts → Customer 360` via the single canonical `admin.customer-360.index` named route; shared label key `admin.customer_360` in both AR and EN. One label does *not* mean combined data access: `Customer360Controller::access()` resolves an effective channel scope from authenticated roles/permissions and `OperationalTenantScope`, and `applyVisibility()` applies it server-side. The index and details are not authorized by Sidebar visibility.

| Actor | Sidebar entry and translated label | Route | Server mode and authorized scope | Negative acceptance |
| --- | --- | --- | --- | --- |
| Global B2B_ADMIN | One `customer_360`, identical AR/EN shared key | `admin.customer-360.index` | B2B accounts; no Retail store IDs | Retail `origin_store_id` filter returns 404 |
| B2C_STORE_ADMIN in Retail A | Same key and label, not a cloned Retail page | Same named route | B2C customer records assigned to Retail A | Foreign Retail B filter and customer detail return 404 |
| Mixed global B2B_ADMIN + Retail A role | Same single shared key, not two unscoped links | Same named route | Current canonical default is B2B (no implicit combined dataset) | Foreign Retail B filter 404; Retail operations remain separately store-scoped |
| Role-less active user | No `customer_360` Sidebar key | Direct URL | No permitted scope | Direct route returns 403 |

Test fixture uses the actual seeded roles, two independently registered Retail-store customers, disposable databases, and **real Laravel HTTP** in AR RTL and EN LTR. It asserts the visible same translated label, *one* instance per authorized actor, same registered named destination and empty route params, view access mode, foreign-store rejection and customer-detail ownership. This proves a shared *name*, not shared tenancy.

## Architecture and affected surfaces

| Surface | Impact |
| --- | --- |
| Backend | Adds focused feature test of the already deployed `Customer360Controller` scope logic. No changed API, request contracts, routes, RBAC roles, schema, middleware, customer data mutation or finance/discount code |
| Dashboard | Tests the existing shared Customer 360 label, named route and server-resolved scope in Arabic and English; no new page/Sidebar placement, CSS, JS or navigation primitive |
| Customer Flutter | Not affected: unchanged API/OpenAPI, identities, payloads, localization and routes |
| Driver Flutter | Not affected: unchanged delivery/dispatch state, permissions, payloads, routes and Flutter code |
| Van Flutter | Not affected: unchanged Wholesale routing/dispatch contracts, payloads, routes and Flutter code |

No app-specific mobile issues are needed for a **test-only** backend change. B2B **VAN ONLY** / B2C **DRIVER ONLY** and credit/finance maker-checker policies remain unchanged; this test does not claim those external contracts have been fully exercised.

## Acceptance and evidence

From `backend/`:

```bash
php artisan test --filter=V2SharedCustomer360ChannelScopeTest
vendor/bin/pint --test tests/Feature/V2SharedCustomer360ChannelScopeTest.php
```

Then confirm the exact PR head passes Repository Policy, FOODEX V2 Isolation, localization, Laravel tests, security/PHPStan and real MySQL/Redis install-upgrade-recovery. No changes to production content or SQL migrations; rollback is reverting only the new test and QA note.

Static HTTP/markup tests do not establish real viewport, mobile drawer, keyboard, focus or screen-reader acceptance on V2 staging. Follow the existing QA #2867 for physical-browser responsive evidence before final UI acceptance. Issue #2881 remains OPEN if that prerequisite is not attested.

**Important scope note:** the current shared Customer 360 route chooses a B2B read mode for a mixed B2B+B2C user with B2B account permission; it does not offer a new per-click channel selector. P171's acceptance here proves labels and non-leakage, not a new explicit channel-switching feature. Such a selector would require its own authorized product requirement and test coverage.

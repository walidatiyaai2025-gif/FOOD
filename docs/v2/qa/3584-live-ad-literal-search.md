# V2 #3584 — Literal-scoped Live Ads Dashboard search

**Source:** #2503 source-to-issue; P082 granular authorized View. **Issue:** #3584. **PR target:** `Foodex_V2` exclusively. No `main`/release/tag/production/cPanel.

## Verified source discrepancy

`LiveAdController::index()` previously treated the authorized `q` value as an unescaped SQL `LIKE` pattern on `name`, `title_ar` and `title_en`. `%`/`_` matched arbitrary rows rather than the manager's literal text. This is **search accuracy**, not a demonstrated SQL-injection or cross-store authorization bypass.

## Reuse / route / actor / security authority

- **Domain:** Marketing & Communications / Advertising; **entity:** Live Ad; **actor:** authenticated authorized Dashboard advertising manager; **user goal:** find an existing live-ad record by its visible bilingual title or internal name; **workflow:** existing Data Explorer / list and filter.
- **Placement:** REUSE canonical `GET /admin/live-ads`, navigation via the already-authorized Advertising destination; `navigation_type=SIDEBAR`; existing exact-record actions, mobile/desktop list UI, search, status/channel controls, pagination and back path retained. No new route, menu item, component, field, free-form technical ID, form, builder, or changed UI layout.
- **Authorization:** unchanged `authorizeAccess($request, 'live_ads.view')` and `visibleAds($actor)` server-side visibility; store flags, tenant/channel and action-specific manage authorization still authoritative. The query is grouped **inside** the existing visible scope, not an OR that can bypass it. Unprivileged user 403.
- **Search semantics:** one parameter-bound literal substring pattern for fixed `name`, `title_ar`, `title_en`: `str_replace(['!', '%', '_'], ['!!', '!%', '!_'], q)` surrounded by deliberate `%`; `LIKE ? ESCAPE '!'` works on supported SQLite/MySQL/MariaDB. No user-controlled column or SQL syntax; no persistence/mutation.
- **States:** existing empty `q`, active/inactive filter, `b2b`/`b2c` filter, priority then newest ordering, 25 per page, query propagation, edit/toggle and audit unchanged.
- **UI quality:** existing desktop/tablet/320–430px mobile web responsiveness, AR/EN title rendering, RTL/LTR, accessible search controls and auth-safe navigation unchanged; backend result membership only.

## Real HTTP regression plan

`backend/tests/Feature/V2LiveAdLiteralSearchTest.php` uses `RefreshDatabase` and seeded canonical roles:
1. Super Admin searches literal combined `%_!`, individual `%`, `_`, `!`, ordinary English and Arabic title substrings; nonmatching wildcard lookalike is excluded; verify no audit/ad mutations.
2. Retail store administrator sees only own-store B2C rows, status/channel filter interaction and inactive state; sibling B2C store and B2B records remain inaccessible; Super Admin can inspect authorized channel buckets.
3. Unprivileged authenticated user gets HTTP 403 on direct Live Ads search.

**Per-app impact matrix:** Dashboard+Laravel web controller: read/search change. Customer app: no API route/JSON/popup payload or Flutter change. Driver app: no API/runtime/dispatch change. Van app: no API/runtime/stock/visit change. B2B=VAN ONLY / B2C=DRIVER ONLY retained. No cross-app/mobile issue needed for this read-only Dashboard result change.

## Completion evidence (record only verified results)

- Scope: `backend/app/Http/Controllers/Admin/LiveAdController.php` list query; new focused test; this QA document.
- Required evidence: PHP syntax, Pint, PHPStan, security/OpenAPI, full Laravel, AR/EN, MySQL/Redis deploy acceptance and terminal Required CI gate on exact **final PR head**, plus Repository Policy and V2 Isolation.
- Actual signed release or live UAT: **not attempted or claimed**. Final consolidated UAT belongs to open master #2503. PR may merge into `Foodex_V2` only after exact-final-HEAD CI and independent review succeed; close leaf with tested SHA, integration SHA and CI links.

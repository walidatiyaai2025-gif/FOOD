# #3603 — Scoped literal Brand/Unit search (P082 / P223)

## Placement and source authority

- Base: `Foodex_V2` ONLY. Never target `main` or deploy/promote releases.
- Source: `P082` View/Search authorization; `P223` Catalog & Pricing → units/reference lookups, existing closed sidebar #2553; master #2503.
- **REUSE** existing `GET /admin/lookups`, `admin.lookups.index` and Brands/Units tabbed grid (50/page). No additional Sidebar, route, form, endpoint, text key or stylesheet.
- Authorized roles: `lookups.view` decision and `LookupScopeService::visible`; Retail Store Admin at assigned store, Wholesale B2B_ADMIN at global/B2B, Super Admin existing support-access rules on mutations only. Query remains read-only, with unchanged explicit foreign-store 404, unauthorized 403 and status/scope filters.
- Free-text search is literal across name/name_ar/name_en and Brand slug or Unit code. Fixed table selection comes from validated `brands|units` tab, never input SQL identifier. Escape `!`→`!!`, `%`→`!%`, `_`→`!_` and bind `'%'.escaped.'%'` with `LIKE ? ESCAPE '!'` to match SQLite and MySQL/MariaDB. No schema/data migration.

## Scoped regression / test evidence

`backend/tests/Feature/LookupManagementCenterTest.php` authenticates HTTP against the canonical Blade renderer with `RefreshDatabase` + `CoreReferenceSeeder`:

| Area | Regression |
| --- | --- |
| Brand symbol search | Literal `%` in slug and display name; `_` in Arabic name; `!` in name; lookalikes with `X` excluded |
| Unit symbol search | Literal `%` in code, `_` in English and Arabic name, `!` in name; wildcard lookalikes excluded |
| Channel/store | Store A cannot see Store B and explicit foreign `store_id` returns 404; Wholesale B2B excludes Retail values |
| Permission | Authenticated roleless user rejected with 403; no permission via querystring |
| Filters/UI | Inactive vs active records, ordinary English search, RTL Arabic and LTR English HTML, existing tab and page reused |
| Data integrity | Authenticated GETs do not alter Brands/Units rows or audit event count |

No additional end-user locale keys: existing AR/EN/RTL/LTR templates and responsive 320/360/390/412/430/768px composition are unchanged. The same server-authorized table renderer is reused (no route/UI drift).

## Full surface matrix

| Surface | Classification | Why |
| --- | --- | --- |
| Laravel Backend | affected | Literal bound filtering only on existing lookup index |
| Management Dashboard | affected | Visible Brands and Units query accuracy without template change |
| API | not affected | No API route/request/response/DTO altered |
| Customer Flutter | not affected | No Flutter code, mobile auth or product API contract altered |
| Driver Flutter | not affected | No dispatch/Driver API change |
| Van Flutter | not affected | No Van inventory/visit/route/dispatch contract change |

B2B → VAN ONLY; B2C → DRIVER ONLY unchanged. Cross-store and read-only regression asserts source security decisions are preserved.

## Acceptance gating and remaining UAT

- Source scope: exactly three paths; no contact with active V2 PR file fences. One original branch and one PR targeting `Foodex_V2`.
- Required: PHP syntax/Pint/PHPStan/security/OpenAPI; full Laravel, AR/EN, MySQL/Redis; Repository Policy, V2 Isolation and terminal Required CI exact final head **SUCCESS**. Verify review, PR head/base, and SHA-guarded merge; issue closure only after evidence is recorded.
- Manual browser/staging/mobile viewport and business UAT may remain in master #2503 **only after** code/automated source requirements pass. Never report production readiness or deployment.

# V2 T356R10 — Reports Center Sidebar migration

- **Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, `T356R10`.
- **Issue:** #2727; parent #2503. Target **`Foodex_V2` ONLY**.
- **Scope:** Remap the human title of the existing `reports_center` destination to **التقارير والتحليلات / مركز التقارير** in Arabic, and **Analytics / Reports Center** in English. Maintain exactly one canonical route, existing authorization, store and channel scopes. Reuse/extend existing Reports Center rather than introducing a duplicate renderer, route or Sidebar entry.

## Existing authority and placement

| Field | Decision |
| --- | --- |
| Domain / entity | Reporting and analytics / governed management report families |
| Role | Only actors already authorized for `reports.view` on the global center; separately authorized Retail-store reporters retain their B2C workspace |
| User goal | Open an actual report, filter, inspect scoped rows, export via existing audited controller |
| Workflow / frequency / density | Frequent read/filter/export; compact filters, KPI summaries, paginated data |
| Existing parent | Dashboard → Analytics group (`admin.nav_groups.analytics`) |
| Placement / pattern | **Reuse** the existing report index; do not create another page |
| Navigation owner / type | `AdminNavigation::groupsFor` / `SIDEBAR`, key `reports_center` |
| Canonical route / fallback | `admin.reports.index` → existing `ReportController::index`; back through Analytics and shared Dashboard shell |
| Authorization | Existing `reports.view`, effective tenant/store/channel scope in the reporting services; translation never creates a permission |
| UI / responsive | Existing shared responsive Sidebar and Report page, AR/RTL and EN/LTR, no CSS, DOM, focus or link changes |

The existing `AdminNavigation` and `admin_sidebar` registry already group `reports_center` under Analytics with `reports.title` and `admin.reports.index`. Those route/config files are deliberately **not modified**; concurrent #2848 owns the canonical ERP navigation and configuration. The mismatch was the label value, which still read “تقارير الإدارة / Management Reports” instead of the source's “مركز التقارير / Reports Center”. Update the existing paired `reports.title` translations, which correctly also changes the page's main title. Preserve all other translation keys and URLs.

## Real HTTP acceptance and negative matrix

| Actor | Visible destination | Expected HTTP |
| --- | --- | --- |
| SUPER_ADMIN | Exactly one `reports_center` inside Analytics, correct translated title and `admin.reports.index` | Existing authorized report GET 200, matching AR/EN document direction |
| B2C_STORE_ADMIN assigned Retail A | Existing `b2c_reports`, **not** a new global reports-center permission | Assigned-store B2C reports GET 200; foreign Retail B 404 |
| Active account without roles | No `reports_center` | Direct report center GET 403 |

Automated `V2ReportsCenterSidebarMigrationTest` checks the actual Laravel navigation projection, dynamic title translation, named route/permission/params, rendered HTTP response and cross-store/denied routes in both locales. Existing `ManagementReportingSuiteTest` keeps real report export/audit tests while adopting the renamed title. An issue-scoped label is not proof of browser breakpoint, focus or screen-reader acceptance; independent staging QA remains required where applicable.

## Backend / Dashboard / Mobile impact classification

| Surface | Effect |
| --- | --- |
| Backend | Existing translations `backend/lang/{ar,en}/reports.php`, regression assertions and new feature test; no endpoint, middleware, schema, report calculations, audit or exports changed |
| Dashboard | Reports Center item and corresponding page heading are human-readable in AR/EN; existing CSS, responsive mobile/tablet/desktop layout, security and report filters unchanged |
| Customer Flutter | **Unaffected:** no API/OpenAPI, customer-report endpoint, identity, payload, route or Flutter source change |
| Driver Flutter | **Unaffected:** no driver report endpoint, collection, dispatch, route or Flutter source change |
| Van Flutter | **Unaffected:** no Van report endpoint, collection, field operations/dispatch or Flutter source change |

B2B remains Van-only and B2C remains Driver-only. No new mobile issue applies to a Dashboard-only translation change. User data, stored files, database, `.env`, cPanel deployment packages and release artifacts are not modified.

## Exact-head verification and handoff

From `backend/`: `php artisan test --filter=V2ReportsCenterSidebarMigrationTest`, `php artisan test --filter=ManagementReportingSuiteTest`, `vendor/bin/pint --test tests/Feature/V2ReportsCenterSidebarMigrationTest.php`.

Required PR checks: Repository Policy, FOODEX V2 Isolation, Required CI Gate, Backend tests, PHP syntax/Pint/PHPStan, Composer security/OpenAPI, AR/EN localization, and MySQL/Redis real install/upgrade/recovery. Record exact source head, PR URL and merge SHA in Issue #2727. Use one issue branch and one PR; do **not** merge to `main`, create production tags or deploy. When full browser/mobile-web responsive acceptance is still unverified, leave the Issue open with an explicit handoff.

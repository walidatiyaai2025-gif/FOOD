# V2 P384 — Complete current Sidebar registry AR/EN label regression

- **Issue:** [#3420](https://github.com/walidatiyaai2025-gif/FOOD/issues/3420), atomic follow-up to [#2904](https://github.com/walidatiyaai2025-gif/FOOD/issues/2904) and master #2503
- **Scope:** existing `Foodex_V2` Dashboard Sidebar registry labels only; no production release or integration to `main`
- **Source:** P384 terminology/AR-EN requirement, `backend/config/admin_sidebar.php`, `AdminNavigation::groupsFor` and `AdminSidebarManager`

## Coverage and acceptance

The original P384 PR #2944 / `V2ArabicSidebarNamingTest` validates seven specifically standardized Arabic labels and protects configured bilingual display overrides. The separate P381 test audits source/structural routes and three representative role projections, but neither guarantees that **every newly registered group and item label** has an explicit translation in both locales. On this branch the current registry has 90 group/item label definitions; this count is descriptive, **not** hardcoded into the regression.

The new `V2CanonicalSidebarRegistryLocalizationTest` walks every live `config('admin_sidebar')` group and item. Each entry must have a nested, well-formed translation key and a nonblank string in **Arabic and English independently**, checked with Laravel's fallback disabled. A future item whose translated key is missing in only Arabic or only English fails even when the fallback locale has a translation. The test does not whitelist the seven previously standardized labels: it extends coverage to the whole existing registry, including other translation catalogs such as sales, notifications and field operations.

This is regression verification, **not** proof that each label meets every untranslated P384 source-wording decision. Approval of exact target terminology and the full **177-item future Sidebar tree** remains with #2904/#2901/#2503.

## Placement, permissions and display behavior

**Reuse:** existing `AdminNavigation` / `AdminSidebarManager`, existing Dashboard responsive right/left drawer, route permission/tenant/channel/entitlement/readiness guards, and saved `label_ar` / `label_en` overrides. There is **no new screen, route, Sidebar entry, UI text, permission, default, or server authorization decision**. Existing override and HTTP 403 coverage remains in `V2ArabicSidebarNamingTest` and `AdminSidebarManagementTest`; P381 tests preserve actual route existence and three-level depth. This leaf never grants access based on label presence.

## Surface impact matrix

| Surface | Change | Contract impact |
| --- | --- | --- |
| Laravel / Dashboard | Add an automated translation-coverage regression; display and navigation unchanged | No runtime mutation; AR/EN defaults now require an explicit regression |
| Backend/API | No controller, route, permission, serialization or scope changes | No public or admin API contract change |
| Customer Flutter | No file, API, shared localization or deep-link change | Not affected |
| Driver Flutter | No file, API, status, dispatch or localization change | Not affected; B2C Driver only |
| Van Flutter | No file, API, route, custody or localization change | Not affected; B2B Van only |

## Verification and delivery requirements

Run `php artisan test --filter=V2CanonicalSidebarRegistryLocalizationTest` from `backend/` in a fully provisioned test environment; require repository-local PHP style/static checks and the exact-final-head GitHub Actions **Repository Policy**, **FOODEX V2 Isolation Gate**, **FOODEX Required CI Gate**, including its applicable Laravel, AR/EN, security and MySQL checks. The test is intentionally configuration-only (no database or external service), while the existing integration suite remains required for full Dashboard authorization. A green scoped test does **not** certify #2904 or master #2503.

No Setup/Update ZIP, cPanel execution, production deployment, APK publication, GitHub tag, or `main` merge is permitted. Close **only #3420** after its sole PR reaches `Foodex_V2` with exact-head required checks green; keep its parent issues open.

# FOODEX V2 P318 — Applications group, preview and mobile-download authority

Issue #2648 | Master #2503 | Sidebar matrix **P318**, actual source DOCX paragraph **P307** (Applications & Restaurant). Integration target: `Foodex_V2` only.

## Placement — Reuse → Extend → Compose → New Screen

| Decision | Existing authoritative implementation |
| --- | --- |
| Domain / entity | Applications & Restaurant; Dashboard application-management navigation |
| Actors | Platform Super Admin (read-only application preview and global APK management), Retail Store Admin (scoped preview only), roleless/inactive actor (neither); users with multiple roles and explicit DENYs |
| User goal | Discover legitimate app preview, Customer APK, Driver APK and Van APK management entry points without granting new authority or promoting a release |
| Workflow / frequency | Preview during review; mobile app artifact actions when needed, not a recurring automatic side effect |
| Existing parent | `AdminNavigation::groupsFor` → `applications` / Admin Sidebar and Administration Hub for download modal |
| Placement type / navigation | **Reuse** existing `SIDEBAR` business-domain group and 4 existing `CONTEXT_ACTION` destinations; no new group or page |
| Canonical routes | `admin.app-preview.index` (preview); `admin.mobile-apps.customer.download`, `admin.mobile-apps.driver.download`, `admin.mobile-apps.van.download` (platform-only download entry). Return path: existing Administration Hub/Sidebar |
| Role / permission | `app_preview.view` using `AdminNavigation::hasPermissionInScope` and `OperationalTenantScope`, confirmed by actual `AppPreviewController@index`; downloads require global `platform.manage` at `MobileAppDownloadController::adminDownload`. Mere preview permission must never grant download access |
| Active store and channel | Only active assigned Retail B2C stores included in Retail preview; foreign store and unpermitted channel excluded from actual preview data |
| Module/entitlement/readiness | Existing app-preview runtime validates availability and allowed origin; APK artifact readiness is handled by release mirror, not by Sidebar. A missing local APK must not trigger a build/fetch from a GET link. No new ERP/restaurant module activation or unready route |
| UI pattern | Existing responsive Dashboard Sidebar + real App Preview workbench and Admin Download modal; desktop, tablet, mobile web use shared components |
| RTL/LTR | Existing AR/EN `admin.nav_groups.applications` and `admin.mobile_apps.*` labels, direction from authorized preview view; no new translation keys |
| Empty/forbidden/recovery states | Roleless/inactive shows no empty Applications group; direct HTTP request forbidden. Retail explicit store preview DENY removes preview link and server denies direct page; platform APK links forbidden. Existing download modal shows preparation state without side-effect on GET |

**No duplicate preview page or download controller is needed:** existing `AdminNavigation`, `AppPreviewController`, `MobileAppDownloadController`, `AdminSidebarManager` and release mirror remain the sole authority. The existing `MobileAppDownloadTest` owns artifact byte/checksum/version/queue compatibility tests; P318 adds independent **group-level effective authorization and runtime scope** evidence instead of duplicating artifact tests.

## Real HTTP / RBAC / navigation acceptance

New additive feature test: `backend/tests/Feature/V2ApplicationsGroupAuthorityTest.php`, seeded using `CoreReferenceSeeder`, with synthetic users/stores only.

1. **No role:** in Arabic and English, no Applications navigation group, direct preview and all three admin download GETs forbidden; no mirror queue or outgoing HTTP.
2. **Super Admin:** exactly one Applications group with exactly four registered, real routes: preview, Customer/Driver/Van downloads; download links explicitly require `platform.manage`; preview is scoped by its own `app_preview.view` runtime guard; all routes resolve, both locales.
3. **B2C Store Admin:** only app-preview link in group, *no* mobile APK management links; actual preview GET renders in both locales with RTL/LTR and includes only assigned Retail store in `retailStores`; excludes other store and wholesale; direct three download GETs forbidden with no queue/network calls.
4. **Multiple role scopes:** a Retail Store Admin plus global Finance remains preview-only and cannot acquire `platform.manage` or APK download capability from a second role lacking that authority.
5. **Explicit scope DENY:** per-user `app_preview.view` denial for the only assigned store removes the Applications group; direct preview returns 403.
6. **Inactive privileged actor:** no Applications Sidebar group even when `SUPER_ADMIN` is present.

The fixture makes no live data changes. It checks genuine controller/view/role behavior, not a mocked Sidebar. No production files, API contracts, permission grants, route names or application release versions are altered.

## Cross-application and system effect assessment

| Surface | Result | Rationale |
| --- | --- | --- |
| Laravel Backend / Dashboard | **Issue-scoped additive test only** | Verifies actual permissions, scoped preview view and admin app download guards, reuses existing responsive navigation |
| Customer Flutter | **Not affected** | No customer application, runtime API, auth, order, storefront, deep-link or actual downloadable binary changed |
| Driver Flutter | **Not affected** | No Driver app, assignment/status, collection, offline, driver APK or mobile API change |
| Van Flutter | **Not affected** | No Van app, van-only B2B dispatch/stock/routes, offline, release artifact or API change |
| Installer/update/runtime, queue/cache/mail | Not affected | No build/release/ZIP, mirror retry, installation action, queue job, migration or runtime configuration change |

Production fulfillment constraints remain **B2B=VAN ONLY, B2C=DRIVER ONLY**. Since no mobile source or public API was modified, no related mobile implementation issue is necessary.

## Tests, CI, release and closure discipline

Run latest-HEAD Repository Policy, FOODEX V2 Isolation Gate and FOODEX Required CI Gate including Laravel full tests, PHP syntax/Pint/PHPStan/Composer security/OpenAPI, AR/EN localization, real MySQL/Redis fresh install/upgrade/recovery acceptance and terminal `required-ci-gate`. Fix failures on the original branch/PR; never bypass or repeat another worker's lane.

Only when all applicable exact-final-head checks are GREEN and PR base `Foodex_V2` verified, squash-merge into `Foodex_V2`, record merge SHA and complete leaf with documented evidence. Manual device/browser staging/UAT is **DEFERRED_TO_V2_FINAL** by owner decision on #2503. A green CI on this tiny additive change does *not* certify signed APKs or actual final Setup/Update ZIPs; those separate V2 gate issues remain open.

No merge to `main`, release/tag creation, automatic promotion or production/cPanel deployment without separately explicit written owner approval.

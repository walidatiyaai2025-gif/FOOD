# V2 #2651 — Driver APK download Sidebar authority (P321)

Status: **candidate implementation; exact-head automated checks pending**. This is not production/mobile-device acceptance.

## Placement and source authority

- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P321, parent #2503.
- Business domain: Applications & digital channels; entity: Android Driver release APK; user role: platform-level administrator with effective `platform.manage`; goal: retrieve the approved Driver APK or get truthful preparation state; workflow: occasional maintenance/download.
- Existing parent: Applications Sidebar group and Administration Hub. Pattern: one authorized action with preparation/status in existing Hub, not a new page; data density: one release entry.
- Placement **Reuse**: `AdminNavigation::groupsFor` key `mobile_driver_download`, canonical label `admin.mobile_apps.driver_download`, route `admin.mobile-apps.driver.download` → `MobileAppDownloadController::driver`. Registry `backend/config/admin_sidebar.php` owns global `platform.manage` exposure.
- Navigation type: `SIDEBAR` with `ADMIN_SETTINGS` pending fallback at `admin.administration.index?download_app=driver`. Desktop, tablet, mobile web share the existing responsive Sidebar/Hub; AR/RTL and EN/LTR translations are already present. Keyboard/focus/touch are handled by existing shared components; no new markup.
- Available artifact: authorized GET redirects to the real versioned local APK `public.mobile-apps.versioned`; pending/unavailable artifact: authorized GET redirects to existing Hub. Explicit authorized POST `admin.mobile-apps.prepare` or `retry` schedules work. GET must not schedule a queue job or make external HTTP requests.
- Runtime, permission, module and tenant authority: effective `platform.manage` on the server, not an independent visibility checkbox. APK availability is a release-artifact readiness state, not a reason to invent a download link or remove authorization guards. No independent Driver-download module entitlement is configured on this canonical controller.

## Access and negative boundary matrix

| Actor | Sidebar | Protected Driver APK GET/status/prepare | Expected behavior |
| --- | --- | --- | --- |
| Active SUPER_ADMIN with `platform.manage` | Visible | Allowed | Redirects to release APK when ready, otherwise Hub pending state |
| Active B2B_ADMIN without global grant | Hidden | HTTP 403 | Wholesale role cannot administer platform APK distribution |
| Active B2C_STORE_ADMIN assigned a Retail store | Hidden | HTTP 403 | Store scope cannot grant global distribution rights |
| Active roleless account | Hidden | HTTP 403 | No artificial Sidebar permission |
| Inactive SUPER_ADMIN | Hidden | HTTP 403 on direct download | Active-session requirement holds |

## Automated evidence and explicit limits

- Added `backend/tests/Feature/V2DriverApkDownloadSidebarAuthorityTest.php`: AR/EN labels and canonical route; authorized non-ready artifact fallback and zero queue/HTTP mutations; store-scoped Retail, wholesale and roleless 403 on GET/status/prepare; inactive owner 403.
- Existing `backend/tests/Feature/MobileAppDownloadTest.php` covers the shared controller's ready APK response, versioning, SHA-256 headers, Customer/Driver/Van local mirror and explicit asynchronous preparation.
- Intended local preflight: `php artisan test --filter=V2DriverApkDownloadSidebarAuthorityTest`; `php artisan test --filter=MobileAppDownloadTest`; `vendor/bin/pint --test tests/Feature/V2DriverApkDownloadSidebarAuthorityTest.php`. Required exact-final-HEAD CI still must prove Repository Policy, V2 Isolation, Laravel/Pint/static/security/localization/MySQL-Redis install-upgrade-recovery and `required-ci-gate`. Writing this document is not evidence those checks have passed.

## Cross-application impact

- Backend/Dashboard: new isolated test and QA documentation only, using real registered route and existing authorization; no source changes to controller, middleware, Blade, routes, release artifact model or Sidebar.
- **Customer:** no Flutter, API response, APK, checkout or authentication behavior changed; no mobile impact issue needed.
- **Driver:** Driver APK admin-download contract is verified, not changed. No Flutter/UI/API/permissions/dispatch edits; no Android binary built or published in this issue.
- **Van:** separate Van APK route and B2B Van operations are unchanged. B2B VAN ONLY and B2C DRIVER ONLY remain in effect.
- AR/EN RTL/LTR and responsive/a11y use canonical preexisting components; manual browser/device/UAT remains `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` under #2503, and is not claimed complete.

## Completion constraints

Merge only via the one issue-scoped PR into `Foodex_V2` after all exact-head required CI checks succeed and full P321 scope is confirmed, then comment PR + SHA + CI evidence and close leaf as completed. No direct `main` merge, release tag, production publication, cPanel update or live server action.

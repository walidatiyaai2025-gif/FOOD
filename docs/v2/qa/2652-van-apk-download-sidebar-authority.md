# V2 #2652 — Van App download Sidebar authority (P322)

Status: **candidate implementation; automated exact-head CI not yet verified**. No production or APK publication is performed.

## Canonical product placement

- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P322, parent #2503.
- Business domain: Applications & digital channels; entity: Android Van APK; persona: active global platform administrator with effective `platform.manage`; user goal: download the authorized current Van APK or see truthful preparation state. Frequency: occasional administration, not routine dispatch.
- Existing parent: `applications` Sidebar group and Administration Hub. **Reuse** before Extend/Compose/New Screen; no duplicate route or menu. Navigation type: `SIDEBAR` → current download action, with `ADMIN_SETTINGS` fallback.
- Canonical Sidebar item: `mobile_van_download`, translation `admin.mobile_apps.van_download`, `AdminNavigation::groupsFor` and `backend/config/admin_sidebar.php` global `platform.manage` scope.
- Canonical HTTP: `admin.mobile-apps.van.download` → `MobileAppDownloadController::van`. For a locally verified artifact, GET redirects to `public.mobile-apps.versioned` for app `van`; when missing/pending, it redirects to `admin.administration.index?download_app=van` with an existing localized status message. Explicit authorized POST `admin.mobile-apps.prepare`/`retry` queues mirror work; GET does not.
- State/entitlement: global management permission is enforced by Gate and Dashboard access middleware, not merely menu visibility. Artifact readiness is a genuine availability gate; no separate per-app Van download entitlement exists in the existing controller. No false APK-ready state or artificial bypass is introduced.
- Desktop/tablet/mobile web and AR RTL/EN LTR: existing FOODEX shared Sidebar and Admin Hub navigation. No Blade, layout, CSS or interaction control is changed; keyboard/focus/touch semantics remain under existing shared components. Administration Hub is the fallback/back path.

## Roles and HTTP access

| Actor/state | Sidebar visibility | Protected Van download/status/prepare |
| --- | --- | --- |
| Active SUPER_ADMIN with effective `platform.manage` | Shown | Allowed: versioned APK if ready, Administration Hub if pending |
| Global B2B_ADMIN without platform entitlement | Hidden | 403 |
| B2C_STORE_ADMIN with legitimate Retail store assignment | Hidden | 403, no cross-store authority escalation |
| Active roleless actor | Hidden | 403 |
| Deactivated SUPER_ADMIN | Hidden | Direct download 403 |

The public `/downloads/apps/van/latest.apk` is a separate public distribution route and does not grant rights to the private administration endpoints.

## Regression and acceptance evidence

New `backend/tests/Feature/V2VanApkDownloadSidebarAuthorityTest.php` checks the actual Van Sidebar item and route name in both locales, authorized pending-artifact Hub redirect with no queue/network calls, negative global/store/roleless/inactive HTTP decisions and management status/prepare denies. The existing `backend/tests/Feature/MobileAppDownloadTest.php` verifies ready local file downloads, versioned URL, SHA-256 headers, Customer/Driver/Van identity and missing-artifact delivery.

Preflight commands for relevant CI/local environment:
```bash
cd backend
php artisan test --filter=V2VanApkDownloadSidebarAuthorityTest
php artisan test --filter=MobileAppDownloadTest
vendor/bin/pint --test tests/Feature/V2VanApkDownloadSidebarAuthorityTest.php
```

Before merging, the exact final PR HEAD must have terminal SUCCESS on Repository Policy, V2 Isolation, Laravel full and focused backend, Pint/PHPStan/security, AR/EN, MySQL/Redis fresh-install/upgrade/recovery and `required-ci-gate`. This document does not claim those checks or visual/device UAT have passed.

## Cross-surface impact review

- **Backend/Dashboard:** test and QA documentation only; no controller, middleware, Sidebar, permissions, release storage, API payload or UI source modified.
- **Customer App:** no Flutter, Customer API or checkout changed, no linked mobile implementation issue needed.
- **Driver App:** no Flutter, B2C delivery/driver auth or Driver APK route changed, no app implementation issue needed.
- **Van App:** existing Van APK administration entry is verified, not modified. No Van Flutter, field-operations/dispatch or APK binary changes; B2B VAN ONLY / B2C DRIVER ONLY preserved. No mobile implementation issue needed.
- **Manual acceptance:** browser/device/staging UAT is `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` under #2503/#2750, not claimed as already performed.

## Completion and safety

Only one issue-owned PR to `Foodex_V2`; merge after exact-head CI and source-scope acceptance, then close #2652 with PR/merge SHA/check evidence. No `main` merge, tags, releases, APK/ZIP publication, production deployment or cPanel execution.

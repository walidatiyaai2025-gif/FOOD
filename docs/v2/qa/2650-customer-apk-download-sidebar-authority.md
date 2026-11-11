# V2 #2650 — P320 Customer App APK download authority

Status: **implementation regression / automated verification pending**. This evidence describes the isolated `Foodex_V2` candidate, not a production release or owner UAT.

## Source and placement

- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P320, Sidebar **تنزيل تطبيق العميل**, parent #2503.
- Domain: Applications & digital channels. Entity: versioned Customer Android APK. Role: platform-level administrator with effective `platform.manage`. Goal: reach the currently approved Customer APK or find an honest preparation state.
- Workflow: occasional download / release support. Data density: single action and status; existing parent: Applications group, Administration Hub download modal.
- Placement: **Reuse** the existing canonical `mobile_customer_download` Sidebar item; no second page/route. Navigation type: `SIDEBAR` action to the existing download controller and `ADMIN_SETTINGS`/Hub pending state. No new Sidebar group or download surface.
- Canonical Sidebar: `backend/app/Support/AdminNavigation.php` → `applications` → `mobile_customer_download`. Registry: `backend/config/admin_sidebar.php`, global scope and `platform.manage`.
- Canonical GET: `admin.mobile-apps.customer.download` → `MobileAppDownloadController::customer`. Existing ready artifact: redirect to `public.mobile-apps.versioned`; missing/not-yet-ready artifact: redirect to `admin.administration.index?download_app=customer`, with localized status. Preparation requires explicit authorized POST, not GET.
- Canonical backend artifacts: `MobileReleaseArtifactMirror`, `MobileReleaseArtifact`, `AppVersion`. No APK, version, cryptographic signature, release contract or production data is changed by this PR.
- Active context: Customer app, current Android release-policy version. Desktop/mobile and Arabic RTL/English LTR use the existing responsive Sidebar/Hub, keyboard/focus/translated controls. Fallback/back path: Administration Hub; the controller never creates a blank or dead download screen.

## Authorization and state matrix

| Actor/state | Sidebar item | Dashboard GET/management API | Notes |
| --- | --- | --- | --- |
| Active SUPER_ADMIN with `platform.manage` | Visible | Allowed; redirects to ready versioned APK or authorized Hub pending state | No GET-side queue/network writes |
| Active B2B_ADMIN without `platform.manage` | Hidden | 403 for download, status, prepare | Wholesale tenant access does not grant platform release management |
| Active B2C_STORE_ADMIN with assigned Retail store | Hidden | 403 for download, status, prepare | Store ownership does not grant cross-store/global release management |
| Active roleless user | Hidden | 403 for download, status, prepare | No role/visibility bypass |
| Inactive SUPER_ADMIN | Hidden | 403 on direct download | Activation/authorization guard applies even when role assignment remains |
| Pending/unavailable local APK, authorized owner | Visible | Hub redirect with preparation message; no fabricated APK or silent 503 for admin navigation | Explicit prepare/retry POST required |

Authorization belongs to the HTTP/controller/session layer, **not** to Sidebar visibility. The public `/downloads/apps/customer/latest.apk` route is a separately designed public delivery path and does not grant access to the private admin preparation API. There is no separate Customer APK "module enabled" entitlement in this existing controller; the meaningful readiness gate is artifact availability, which must not be misrepresented as an installed build.

## Automated evidence

New focused Laravel coverage:
`backend/tests/Feature/V2CustomerApkDownloadSidebarAuthorityTest.php`

1. Real authorized Applications Sidebar route in Arabic and English; canonical route and effective permission; missing-APK Hub fallback; zero GET-side queue/external requests and no fabricated local artifact.
2. B2B, assigned-store B2C and roleless actors: hidden link and server-side HTTP 403 on both management GET and prepare/status APIs.
3. Deactivated Super Admin: Sidebar hidden and direct HTTP denied.

Existing regression suite `backend/tests/Feature/MobileAppDownloadTest.php` additionally covers the ready local Customer APK download with release version/hash headers, immutable/versioned filename, redirect, pending fallback and per-app Customer/Driver/Van artifact identity.

Suggested checks:
```bash
cd backend
php artisan test --filter=V2CustomerApkDownloadSidebarAuthorityTest
php artisan test --filter=MobileAppDownloadTest
vendor/bin/pint --test tests/Feature/V2CustomerApkDownloadSidebarAuthorityTest.php
```

All required `Repository Policy`, `FOODEX V2 Isolation Gate`, Laravel/Pint/PHPStan/security, AR/EN, MySQL/Redis fresh-install/upgrade/recovery, and terminal `required-ci-gate` must be successful on the **exact final PR HEAD**. No local, CI or runtime pass is claimed by writing this evidence file.

## Cross-surface impact classification

- **Backend:** isolated additional authorization/route regression tests only; no controller, API, storage, release or session code changed.
- **Dashboard:** verifies the existing Applications navigation and authorized controller fallback; no Blade/CSS/JS/route changes.
- **Customer:** no Flutter source, endpoint, release identity or installation behavior changed. Existing Customer APK delivery is covered by `MobileAppDownloadTest`.
- **Driver:** no app source, API, download or dispatch contract changed; its independent Sidebar action remains unchanged.
- **Van:** no app source, API, download or field-operations contract changed; B2B VAN ONLY and B2C DRIVER ONLY remain intact.
- **AR/EN, responsive/accessibility:** reuse the existing translated, responsive Sidebar/Hub instead of inventing new UI primitives. New regression checks both localization resources. Manual browser/device acceptance remains deferred to final V2 #2503/#2750; not falsely marked as passed.

## Closure gate

The leaf can close only after complete requirements are evidenced, the one PR has merged into `Foodex_V2` with exact-head required CI success, and the issue records PR, SHA and CI links plus `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL`. No `main` merge, production deployment, release tag, Customer APK publication or new ZIP occurs in this issue.

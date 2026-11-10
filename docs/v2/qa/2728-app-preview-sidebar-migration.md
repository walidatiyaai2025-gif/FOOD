# V2 #2728 / T356R11 — App Preview Sidebar migration

**Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, `T356R11`. Parent program #2503. Integration branch: **`Foodex_V2` only**.

## Existing architecture, placement and authority

- **Business domain:** Applications (`admin.nav_groups.applications` / التطبيقات). Existing single navigation key: `app_preview`.
- **User goal:** Open the already-shipped live, read-only Customer/Driver/Van Flutter preview from the Management Dashboard, choosing the currently authorized channel/store/identity. The frontend is the shared runtime, **not a second static mockup**.
- **Placement choice:** **Reuse** existing `Applications → App Preview` SIDEBAR entry and `admin.app-preview.index`, `AppPreviewController::index`, shared Dashboard shell and `admin.app-preview` Blade. No additional route/tab/screen/navigation item. Back via the Applications group.
- **Role and tenant guard:** Existing `app_preview.view` permission through `AdminNavigation::routeItemScoped()` and server `AppPreviewController::index()` using effective B2B authority and store-scoped B2C access. Per-identity preview session endpoints, credentials, same-origin and read-only rules remain unchanged.
- **Human title:** Arabic existing plural `معاينة التطبيقات` becomes the source's singular **`معاينة التطبيق`**, for both `admin.app_preview` (Sidebar) and `admin.preview_center.title` (page heading). English `Application Preview` is already the proper translation and remains unchanged. The plural expression in explanatory descriptions about all three apps is intentionally preserved.
- **Active context:** Existing `navContext='app_preview'`, `applications` group. Same URL and fallback. No new menu node, permission, hidden registry alias or stale technical key.
- **UI and accessibility:** Same responsive Desktop/Tablet/Mobile Web Dashboard shell, keyboard/focus primitives, AR RTL and EN LTR. No CSS, HTML DOM, JS or interactivity changed. Browser breakpoint/focus/screen-reader acceptance remains separately required; tests cannot substitute a real staging browser audit.
- **Business fields:** No new user input; existing select/lookup for application, authorized store, identity and device profile remain authoritative. No IDs/JSON introduced to business users.

## Real HTTP test matrix

| Actor | Sidebar projection | HTTP behavior |
| --- | --- | --- |
| B2B_ADMIN with `app_preview.view` | Exactly one `app_preview` item in Applications, original `admin.app-preview.index`, title follows locale | GET 200 with `data-preview-runtime-contract=shared-flutter-v1`, `data-preview-safe-mode=read_only`, AR RTL / EN LTR |
| B2C_STORE_ADMIN assigned Retail A | Same single destination (no B2C clone) | GET 200, authorized Retail A shown, unrelated Retail B not shown |
| B2C_STORE_ADMIN requesting target discovery in Retail B | Original destination still exists but store access is independently denied | GET JSON 403; cannot enumerate foreign target identities |
| Active roleless actor | No Sidebar preview link | Direct GET preview 403 |

`backend/tests/Feature/V2AppPreviewSidebarMigrationTest.php` exercises these through seeded roles, real store assignments and Laravel HTTP. Existing `AppPreviewDashboardTest` and `AppPreviewDashboardBridgeTest` keep more detailed runtime, session lifecycle, identity, token non-disclosure and store isolation regression checks. No regression in authoritative `docs/architecture/APP_PREVIEW_ARCHITECTURE.md` or `docs/quality/APP_PREVIEW_FINAL_ACCEPTANCE.md`.

## Surface impact classification

| Surface | Impact / evidence |
| --- | --- |
| Laravel Backend | Only existing Arabic display strings and a new HTTP feature test; no routes, middleware, permissions, migrations, API/OpenAPI, request/response, stores, sessions, secrets, release artifacts or vendor dependencies changed |
| Dashboard | Existing Applications Sidebar label and Preview page heading now use the requested Arabic term; English untouched; UX/layout/read-only runtime preserved |
| Customer Flutter | **Not affected:** app preview uses the preexisting shared runtime and no Customer Flutter source/API/identity contract changed |
| Driver Flutter | **Not affected:** existing Driver preview runtime/permissions unchanged; no driver/dispatch code or API changed |
| Van Flutter | **Not affected:** existing Van preview runtime/permissions unchanged; no fleet/dispatch code or API changed |

The B2B=VAN ONLY and B2C=DRIVER ONLY production dispatch rules remain unchanged; no V2 mobile issues are created for a Dashboard-only label correction.

## CI, recovery, final acceptance

Run from `backend/`: `php artisan test --filter=V2AppPreviewSidebarMigrationTest`, `php artisan test --filter=AppPreviewDashboardTest`, `vendor/bin/pint --test tests/Feature/V2AppPreviewSidebarMigrationTest.php`. Required GitHub exact-head gates: Repository Policy, FOODEX V2 Isolation, `required-ci-gate`, Laravel tests, AR/EN localization, PHP/Pint/PHPStan/security/OpenAPI, MySQL/Redis actual fresh install/upgrade/recovery.

Rollback of this PR consists solely of reverting the two Arabic label values and test/docs file; no database/file/data recovery needed. No release setup/update ZIP is produced by this test/display-only PR. Keep Issue #2728 open after merge until independent V2 staging desktop/mobile web, AR/EN responsive, keyboard, focus and screen-reader evidence is accepted; **no production deployment or main merge**.

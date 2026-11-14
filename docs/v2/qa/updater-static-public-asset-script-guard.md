# FOODEX V2 #3410 — Static-only public ZIP asset execution boundary

**Source:** G005 Admin Update ZIP #2746; P112 release gate #2846; master #2503. Integration **Foodex_V2 ONLY**. No main, tag, public release, production or cPanel update.

## Original source / security finding

`LaravelUpdateRuntime::normalizePublicAssetPermissions()` recognizes that a shared host may serve the release root directly. It mirrors only **static public files** under `assets/`, `brand/`, `demo/`, and `preview/`, explicitly noting these paths must never be used to mirror PHP/front controllers or `.htaccess`. However, its upstream `safeRelativePath()` previously allowed `assets/test.php`, `brand/script.php.jpg`, `preview/.htaccess` and `demo/web.config`, treating these as ordinary release files. The ZIP preflight could therefore accept active file types in a web-exposed static namespace and reach extraction.

This is an authorized updater package **preflight policy gap** in potentially web-served asset folders. It does not establish arbitrary untrusted ZIP upload, actual PHP execution, a production breach, or complete verification of package signatures.

## Bounded implementation and compatibility

The canonical runtime path guard now rejects, **only** inside the four static-only release-root namespaces:

- PHP/PHTML/PHAR and other common server-executable script extensions, case-insensitively, even when followed by an additional extension (e.g. `foo.PHP.jpg` on Apache multi-extension configurations).
- `.htaccess`, `.user.ini`, `web.config` and their extension-suffixed variants.

Legitimate static ZIP assets (`.css`, `.js`, `.png`, `.svg`, etc.) remain accepted. Laravel-controlled `backend/public/index.php` and `backend/public/.htaccess` are **outside these static-only roots** and keep their normal source/upgrade behavior.

The shared `safeRelativePath()` validation runs in preflight and prior to any backup/extraction path mutation. Existing alias/deduplication, symlink traversal, protected `.env`/`storage`/`vendor` restrictions, signed-package requirement, version checks and rollback flow remain unchanged.

## Test evidence

New Laravel Feature test `backend/tests/Feature/V2UpdaterStaticPublicAssetScriptGuardTest.php` uses actual synthetic ZIP archives and random, isolated subpaths under the release root:

1. One malicious member across each static root, including uppercase/multiple extensions, `.htaccess`, `.user.ini`, and `web.config`; preflight fails before backup state or target files appear.
2. Valid first CSS entry plus forbidden second PHP asset; both direct backup and extraction reject the entire archive before changing an existing sentinel or creating a backup.
3. A valid four-root CSS/SVG/JS/PNG archive passes preflight, backs up files, extracts and rolls back to original state.
4. A validation-only ZIP containing `backend/public/index.php` stays permitted, without ever overwriting the actual Laravel front controller.

All generated ZIPs and isolated fixture paths are removed in teardown. No live data, secrets, users, public scripts or release artifacts are altered. Existing ZIP Slip, directory symlink, duplicate alias, canonical-entry and rollback test suites are unmodified and must remain green.

## App / localization impact

| Surface | Impact |
| --- | --- |
| Backend | Update ZIP static release asset path validation only |
| Dashboard | Existing Super Admin System Updates upload errors; no new route, UI string, translation, confirmation or navigation |
| Customer | No public API/Flutter/source change |
| Driver | No public API/Flutter/dispatch or wallet change |
| Van | No public API/Flutter/route or consignment change |
| AR/EN / RTL/LTR / accessibility | No UI or copy changed, existing feedback surface reused |

B2B = VAN ONLY and B2C = DRIVER ONLY remain unchanged.

## Acceptance / remaining gates

Exactly three scoped files: updater runtime, new isolated Feature test, this QA document. Require Repository Policy, V2 Isolation and FOODEX Required CI **all SUCCESS on the exact PR HEAD**, including PHP syntax/Pint/PHPStan/security/OpenAPI, Laravel full tests, AR/EN and actual MySQL/Redis clean install, upgrade and recovery. Confirm current branch ownership and PR base `Foodex_V2` before a SHA-guarded squash merge. Close only atomic #3410 after independently verifying the fix inside `Foodex_V2`; keep G005 #2746, P112 #2846 and master #2503 OPEN until complete signed Setup/Update ZIP artifacts and supported-version acceptance.

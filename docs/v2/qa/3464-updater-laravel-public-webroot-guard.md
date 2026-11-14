# V2 #3464 — Laravel public webroot executable Update ZIP member guard

**Source:** P112 / G005 (#2746), #2846, #2503. **Target:** `Foodex_V2` only. No main, production, release, tag or cPanel mutation.

## Independent gap and boundary
Merged #3410 / PR #3411 correctly rejects executable scripts and Apache/PHP handler overrides under the shared-host *release-root static mirrors* `assets/`, `brand/`, `demo/`, `preview/`. Prior to #3464, `LaravelUpdateRuntime::safeRelativePath` did not apply this denial to the distinct web-served Laravel DocumentRoot `backend/public/`. A checksum-matching, otherwise accepted ZIP could include `backend/public/assets/payload.php` or `backend/public/images/.htaccess` and stage it. This is defense in depth within the existing Super Admin, trusted signature, hash and version preflight; no unauthenticated upload, production exploit, or live attack has been demonstrated.

## Code and safe compatibility
One shared server-side path predicate is used by ZIP preflight, file-backup path enumeration, extraction and rollback target validation. It rejects script, multi-extension script and server handler configuration paths anywhere under `backend/public/`, including casing changes, while retaining existing release-root mirror protections. Only exact `backend/public/index.php` and `backend/public/.htaccess` remain permitted as Laravel bootstrap/rewrite files. Ordinary CSS/JS/SVG/images pass. Existing archive size/member limits, duplicate member detection, path traversal/symlink checks, versions, signer, protected `.env`/storage/vendor, backup, recovery and administrative authorization are untouched.

## Test proof
`backend/tests/Feature/V2UpdaterLaravelPublicWebrootGuardTest.php` creates *actual synthetic ZIP archives* with nested PHP, PHP multi-extension, PHP8, PHTML, PHAR, CGI, Python, Apache .htaccess, .user.ini, IIS web.config, and case variants. Tests require rejection in preflight/backup/extract before modification. An existing CSS sentinel must retain original contents with a mixed valid/invalid archive and no backup directory must be created. A separate preflight-only positive case contains the canonical Laravel front controller/rewrite plus benign CSS/JS/SVG without ever writing to the real front controller. Fixtures are ephemeral, scoped under one random test directory and cleaned.

**Required GitHub evidence before integration:** exact-final-head Repository Policy, FOODEX V2 Isolation, FOODEX Required CI with PHP/Pint/PHPStan/Composer security/OpenAPI, Laravel suite, AR/EN localization and MySQL/Redis clean-install/upgrade/recovery where applicable, all terminal SUCCESS. Record run IDs and SHA in issue/PR closure only after verification.

## Impact and nonimpact
- **Backend:** safer internal ZIP path preflight; no routes, API response schema, DB schema, scheduled tasks or runtime deployment changed.
- **Dashboard:** existing privileged Settings → System → System Updates validation error path remains; no new UI labels, layout or navigation.
- **Customer app:** unaffected — no REST/auth/Flutter/notification change.
- **Driver app:** unaffected — no B2C dispatch/Flutter contract change.
- **Van app:** unaffected — no B2B custody/Flutter contract change.
- **AR/EN, RTL/LTR, a11y:** no user-facing text/navigation change; existing localization gates still required.
- **Security/SoD:** signed update/admin permissions, ZIP integrity, store/channel isolation and B2B VAN ONLY / B2C DRIVER ONLY unchanged.

## Acceptance split
This atomic security leaf may close as `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED` **only** after exact-head required checks succeed and issue-specific PR merges into `Foodex_V2`. Parent #2746, #2846 and #2503 stay OPEN: they still require actual signed, trusted Setup/Update ZIP, packaged clean-install/historical upgrade/backup/recovery verification and final staging/human signoff. No inferred production release.
# V2 G004 / #2745 — deterministic Composer dependencies for Setup ZIP

**Source:** P112 / G004, parent #2846 and master #2503. **Integration base: `Foodex_V2` only.** No main promotion, release/tag, cPanel or production actions.

## Reproduced source gap

The isolated Laravel backend previously had `backend/composer.json` but **no versioned `backend/composer.lock`**. The canonical `.github/workflows/release-factory.yml` invokes `composer install --no-dev` to stage the Setup ZIP. Without a lock, identical source SHAs can resolve different Laravel/transitive production package versions; the former backend CI explicitly used `composer validate --strict --no-check-lock`, so that delivery invariant was not enforced.

## Bounded fix

- Check in `backend/composer.lock` generated from **the existing** Composer requirements; includes the production and development dependency graph, immutable package version/source references and Composer content hash.
- Set `config.platform.php = 8.2.0` in `backend/composer.json` so the dependency solver selects packages installable on the project's documented PHP 8.2+ shared hosting minimum, even when the CI development runner is PHP 8.3.
- Backend CI now runs **`composer validate --strict --no-check-publish`**, rejecting a missing, stale or inconsistent lock; no disable-lock escape hatch.
- The canonical release factory validates the same lock **before** resolving production dependencies and building its Setup ZIP. It still installs with `--no-dev` and includes `vendor` in the exact ZIP build; production installation users are not asked to run Composer.
- No runtime application/controller, migration, permissions, `.env`, DB, secret, UI or API file is modified by this slice.

## Local developer preflight (not release acceptance)

On an authorized development device (PHP 8.3.33, Composer 2.11.2), `composer update --no-install --no-scripts` generated 79 production and 35 dev package pins under an emulated PHP 8.2.0 platform. `composer validate --strict --no-check-publish` reported the manifest valid. `composer install --no-dev --dry-run --no-scripts` confirmed 79 locked production installs were platform-resolvable. **No production dependency installation or runtime web acceptance was claimed.**

Attempted Composer security audit could not reach the Packagist advisory endpoint (curl error 28). Security result is **UNKNOWN** until the exact-head `FOODEX Required CI Gate` performs the Composer audit successfully. The developer dry run does **not** replace a real fresh MySQL install from the final ZIP.

## App and security impact

| Surface | Impact |
| --- | --- |
| Backend / Setup | Dependency resolution and release-provenance validation only; runtime APIs and database behavior unchanged |
| Dashboard | No navigation, installer form, server-side permissions, AR/EN strings, route or layout change |
| Customer | No Flutter changes and no mobile API contract or identity changes |
| Driver | No Flutter changes and no B2C dispatch / collections contract changes |
| Van | No Flutter changes and no B2B assignment / offline / visits contract changes |

B2B = **VAN ONLY**, B2C = **DRIVER ONLY**, and store-scoped RBAC remain unchanged.

## Remaining full G004 acceptance (not certified)

This is a dependency-reproducibility prerequisite, **not** a complete G004 implementation. The Issue and any PR must remain **OPEN/DRAFT** until the same exact final Foodex_V2 SHA generates an actual signed `FOODEX-Setup-vX.Y.Z.zip` with production vendor and compiled assets; its publisher authenticity and SHA-256 are verified; the ZIP is unpacked and installed through `/install` on clean MySQL/MariaDB without user-side Composer/Artisan/npm commands; safe `.env`, owner, seeds, scheduler/cache/queue/mail, installer lock and recovery are evidenced; required CI, security/AR-EN and full Customer/Driver/Van impact checks pass. Preserve explicit cPanel document-root/DB privileges limitation and master #2503 final manual UAT.

A successful Composer install or CI dependency check **must never be misreported as an actual signed Setup ZIP fresh-install acceptance**. No merge into main, no publication or live hosting operations.


## Isolated V2 staging ZIP acceptance (unsigned, not release-ready)

The same G004 branch/PR now includes `.github/workflows/v2-setup-staging-acceptance.yml`, triggered only on PRs targeting `Foodex_V2` when Setup/installer/dependency/validation files change. This is an **untrusted, nonpublishing validation** lane with read-only repository permissions; no tag, release, production deployment, or main-branch action is authorized.

The lane checks out the actual `github.event.pull_request.head.sha` (not GitHub's synthetic PR merge commit), verifies its checked-out SHA, resolves `backend/composer.lock` production packages with `--no-dev`, builds the Laravel Setup ZIP with vendor files, and validates package paths, duplicate/case aliases, symlink members, .env exclusion, required runtime files, version and SHA-256. It records the **actual PR-head SHA** in unsigned provenance evidence; it then uses the existing `scripts/validate-fresh-setup.sh` to exercise the *same staged ZIP* on a fresh MySQL 8.4/Redis 7 fixture, including the script's existing Playwright Dashboard/surface assertions. Evidence and the ZIP are uploaded as temporary CI-only artifacts (7 days).

**Acceptance boundary:** The staged archive is explicitly marked `UNTRUSTED_CI_STAGING_ONLY` and `signed: false`, is not a versioned, publisher-signed production installer, and must never be treated as an authentic release. The canonical script programmatically executes installer steps and verifies web pages; it does **not** prove the complete user-driven web-wizard Next/Finish sequence. Until this exact-head workflow finishes, all its results are **PENDING/UNKNOWN**. Even on success, trusted signing, final source/manifest provenance, full interactive installer, cPanel restrictions, repeat/failure/recovery tests and exact-version release packaging remain open under G004/P112. No Issue closure or PR merge is authorized by this bounded acceptance addition alone.

## Staging ZIP path/secret validation hardening (2026-11-14)

Original #3444 now owns `scripts/v2_setup_staging_verify.py` and its isolated synthetic ZIP tests as well as the existing V2 Setup staging workflow. The formerly inline verifier checked basic paths, a single exact `.env` name, symlinks and hash, but did not reject other secret `.env.*` configuration, case/Unicode NFC aliases, file-as-parent collisions, reserved Win32 device components or runtime private storage. These unsafe members must fail **before** attempting unpack/install. The actual staging ZIP build now excludes `backend/storage/app/private/*` (in addition to existing logs/system exclusions); it retains the intentionally public `.env.example` and `.env.production.example` templates needed for an authorized web installer.

The reusable verifier rejects unsafe member components and traversal, symlinks/non-regular/encrypted members, duplicate/canonical aliases and ambiguous parent prefixes, runtime private data, invalid/version-mismatched `VERSION`, unsafe expansion limits and CRC errors. It records the immutable requested exact PR source SHA and package SHA-256 as `UNTRUSTED_CI_STAGING_ONLY`/`signed=false`; the workflow first proves the checked-out Git HEAD equals that SHA. `scripts/tests/test_v2_setup_staging_verify.py` constructs real synthetic ZIPs with allowed and denied cases and runs on every matching PR staging workflow before the ZIP is exercised against MySQL/Redis/Playwright.

This is a **CI-only** safety/acceptance improvement; the documented validator never asserts a trusted release signature, source-publisher identity, or a complete user-driven Next/Finish wizard. G004 #2745 and draft #3444 remain OPEN until digitally signed trusted versioned Setup ZIP provenance, full web setup/negative recovery, clean MySQL/MariaDB, customer/store/roles and final acceptance on one frozen Foodex_V2 commit are independently verified. Backend/Dashboard business APIs unchanged; Customer/Driver/Van Flutter and contract unchanged. B2B Van-only/B2C Driver-only preserved. No `main`, tags, release publication, cPanel or production deployment.

### CI rescue: stale archive member retention

The first exact-head staging run with the new verifier exposed an existing package-construction weakness: an otherwise empty private storage directory is necessary for Laravel initialization, but private files must not ship. After the verifier learned to permit empty directories, the staged ZIP still contained `backend/storage/app/private/.gitignore` despite an explicit rsync content exclusion. The staging workflow had been running `zip -qr` directly against `Release/FOODEX-Laravel-Setup.zip`; `zip` updates a pre-existing target archive rather than reconstructing its member inventory, so stale entries can persist even when absent from the new staging tree.

The workflow now removes that destination archive **before** invoking `zip` and asserts the old path no longer exists. This preserves strict fail-closed verification of private files and avoids weakening the ZIP verifier. Synthetic tests continue to verify private content rejection while allowing empty Laravel storage directory placeholders. The next exact-head staging run must independently confirm both safe ZIP inventory and real MySQL/Redis installation; previous failed runs are not release evidence. No signed release/package publication or production action.

### ZIP Unicode Path extra field ambiguity (2026-11-14)

Setup ZIP staging verification now rejects the Info-ZIP Unicode Path alternate-filename extra field `0x7075` in each member, even if the central ZIP filename and CRC pass in Python. Such an extra field can claim a different extraction target (for example a private `backend/.env.local`) that another ZIP extractor might use. Only the single central-directory member filename may be attested; additional alternative name authority is not safe for the shared-hosting installer. Extra field headers/lengths are checked for complete framing, while unrelated valid ZIP metadata such as extended timestamps (`0x5455`) remains permitted. This is the equivalent G004 hardening to the issue-scoped G006 Update ZIP validator, with no edits to that separate worker's branch.

The added real synthetic ZIP tests exercise: (1) central safe filename with CRC-invalid Unicode Path extra pointing to `backend/.env.local`—Python reads the original member and its CRC successfully but the staging verifier must deny; (2) a valid timestamp extra accepted; (3) malformed extra field length rejected. All earlier ZIP tests and actual exact-source Setup ZIP build/MySQL/Redis/Playwright checks remain mandatory on the final SHA. These checks establish **archive integrity only**; they do not authenticate a publisher signature, validate the signed installer end-to-end, or certify cPanel recovery.

**Impact:** Build/verification only. Laravel Backend runtime and API, Management Dashboard UI, Customer, Driver and Van app sources are unchanged. Public routes, B2B VAN ONLY/B2C DRIVER ONLY, RBAC, roles, account data, user uploads and live secrets are not changed. Original #2745/PR #3444 stay OPEN/DRAFT until full G004 source acceptance plus exact-final-head CI; no `main`, tags, production, release, cPanel or publication.

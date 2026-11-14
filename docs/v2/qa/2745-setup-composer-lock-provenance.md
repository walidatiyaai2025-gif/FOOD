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

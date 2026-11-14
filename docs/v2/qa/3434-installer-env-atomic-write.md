# V2 #3434 — atomic concurrent Web Installer environment writes

**Source**: P112 / G004 Web Installer (#2745), P112 delivery (#2846), master #2503.
**Integration**: `Foodex_V2` only. No main, release tag or live cPanel deployment.

## Evidence and defect

The original `InstallerEnvironment::write()` read the current `.env`, replaced managed keys and atomically renamed a temporary file back into place **without holding a process-wide lock across this transaction**. Two PHP workers can both read the same old content and write disjoint `DB_*` / `MAIL_*` settings; the last rename silently loses the first writer's keys. An atomic rename prevents torn writes, not this lost-update race. `InstallState::token()` was locked separately under #3378 but does not serialize environment writes.

## Implementation

- Validate allowed keys before doing any filesystem work.
- Derive a stable lock file from the configured env path under `storage/framework/installer-env-locks/`; tests may override `foodex.installer_env_lock_path` to an isolated location.
- Validate lock path and ancestors against symlinks; create its directory with restricted permissions, open a **persistent** lock inode and validate regular-file type, link count and matching inode before and after obtaining `flock(LOCK_EX)`.
- Perform **initial .env creation, full read, managed-key replacement, 0600 temporary write, final symlink recheck and atomic rename while holding the lock**; release on success/failure. Never unlink the lock file, because another waiting request could then synchronize on a different inode.
- Fail closed if the read fails instead of replacing a live `.env` with a partially reconstructed file.
- Preserve `.env` user and platform settings, preexisting unmanaged keys and last-write semantics for the **same** managed key. File locks cannot serialize independent external editors which do not participate in the same locking protocol.

## Executable acceptance

New `backend/tests/Feature/V2InstallerConcurrentEnvironmentWriteTest.php` validates:

1. Eight distinct Laravel-bootstrapped PHP CLI workers writing disjoint installer-managed fields concurrently; all keys and an unmanaged sentinel survive, `.env` stays 0600 and lock 0640.
2. Retrying a write keeps the same stable lock inode and preserves other keys.
3. A symlinked lock is rejected without touching external target or existing env.
4. A hardlinked lock is rejected before chmod/mutation, preserving external content.

Existing `V2InstallerEnvDuplicateKeyCanonicalizationTest`, `V2InstallerEnvEscapeRoundtripTest`, `V2InstallerEnvAncestorSymlinkBoundaryTest`, `InstallerEnvironmentSymlinkBoundaryTest`, `V2InstallerConcurrentTokenInitializationTest` remain unchanged and must pass. Run Laravel suite, Pint/PHPStan/security, AR/EN and MySQL/Redis fresh-install/upgrade/recovery in exact-head CI.

## Product and security impact

| Surface | Impact |
|---|---|
| Backend | Safe persistent cross-process lock and fail-closed `.env` write/read path during initial setup; no API route or contract alteration |
| Dashboard / Installer | Existing Web Installer steps and input fields unchanged; concurrent setup retries no longer silently drop independent settings |
| Customer | Not affected: no Customer API/permission/user-status/Flutter changes |
| Driver | Not affected: no Driver API/dispatch/permission/Flutter changes |
| Van | Not affected: no Van API/dispatch/permission/Flutter changes |
| AR/EN, RTL/LTR, a11y | No user-visible language/UI/navigation changes |
| Shared hosting / cPanel / VPS | Lock uses PHP filesystem/flock on writable Laravel storage; no SSH, Composer, Artisan or Terminal actions demanded from production users |

No database migration, dependency update, changes to secret values or output of secret values in tests, and no live installation/updates. #2745, #2846 and #2503 remain open for final release package and end-to-end acceptance.

## CI / merge evidence

Fill with the **latest exact PR SHA**, GitHub Actions run URLs and verified `Foodex_V2` merge SHA after all required gates succeed. Do not treat a draft PR or building a synthetic test as release readiness.

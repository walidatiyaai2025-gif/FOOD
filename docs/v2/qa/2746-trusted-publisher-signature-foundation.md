# G005 / #2746 — Trusted publisher signature and ZIP admission (partial)

## Implemented source behavior

The signed publisher path is integrated into `SystemUpdateController::store`; this is no longer an isolated verifier-only increment:

- The Dashboard requires a detached signed-manifest upload. The controller parses the strict envelope and verifies Ed25519 against the host-pinned publisher key before creating an incoming upload path or calling `UpdateManager`.
- The signed canonical payload binds the publisher key ID, target and minimum versions, archive SHA-256, migration declaration and release notes. The `UpdatePackageValidator` independently hashes the uploaded ZIP and compares its bytes with the signed digest before preflight or backup.
- A signed `contains_migrations=false` value cannot hide PHP migration members in the archive. The archive is inspected before update execution.
- The only default trust source is the host environment (`FOODEX_UPDATE_PUBLISHER_KEY_ID` and `FOODEX_UPDATE_PUBLISHER_PUBLIC_KEY_B64`). No demo key or private signing material is distributed. Missing, malformed or untrusted key material fails closed with bilingual guidance.
- Update ZIP staging now checks all path ancestors for symlinks/non-directories and writability before creating the private incoming directory, then rechecks after creation. The exact-head negative HTTP regression is on the new head and awaits CI.
- `UpdateManager` verifies file and database backups before activation, and performs maintenance-mode rollback after injected activation/health failures. The disposable actual-ZIP workflow covers MySQL upgrade and recovery.

Signature canonicalization uses the domain separator `FOODEX-UPDATE-SIGNATURE-V1`, a newline, and UTF-8 JSON with fixed field insertion order and `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`. Unknown keys, missing Sodium, malformed/noncanonical material, key-ID substitution and modified signed fields fail closed.

## Exact-head evidence

- At `c466d59f80c4b322fb55c40ff6ab1f2cc871534e`, Update ZIP staging run [#44188192582](https://github.com/walidatiyaai2025-gif/FOOD/actions/runs/44188192582) **passed**: it built the actual source ZIP, exercised the signed ZIP/MySQL recovery path, upgraded a populated disposable v125 MySQL installation and recorded nonpublishing provenance. Its CI test key is ephemeral and is **not** a trusted release identity.
- At that same SHA, Repository Policy [#44188192657](https://github.com/walidatiyaai2025-gif/FOOD/actions/runs/44188192657) and V2 Isolation [#44188192492](https://github.com/walidatiyaai2025-gif/FOOD/actions/runs/44188192492) **passed**. Required CI [#44188192764](https://github.com/walidatiyaai2025-gif/FOOD/actions/runs/44188192764) **failed** only C13 and Operational shared acceptance contracts; backend tests and MySQL/Redis deployment acceptance passed.
- Head `5f367fb2663e41e2b454d11c2bb5aee9c3bd6792` added the symlinked staging-path regression. Update ZIP staging [#44194060181](https://github.com/walidatiyaai2025-gif/FOOD/actions/runs/44194060181), Repository Policy [#44194060312](https://github.com/walidatiyaai2025-gif/FOOD/actions/runs/44194060312) and V2 Isolation [#44194060374](https://github.com/walidatiyaai2025-gif/FOOD/actions/runs/44194060374) **passed**. Required CI [#44194060597](https://github.com/walidatiyaai2025-gif/FOOD/actions/runs/44194060597) **failed**: PHPStan flagged the post-`mkdir` recheck as a repeated negated boolean expression, and the shared C13/Operational contracts also failed. The path check was moved behind a fresh-stat-cache helper; that follow-up head still requires exact CI. Strict skill-router, PHP lint and `git diff --check` passed locally. `backend/vendor/autoload.php` is absent locally, so local PHPUnit was not available.

## Remaining G005 acceptance

- No independently verified publisher key or private signer is available in this workspace. Do not invent a key or treat a CI-only test key as trusted. Final trusted signing requires a verified publisher key provisioned outside the ZIP/uploader and an actual versioned signed package.
- Current host configuration accepts one active pinned publisher key. Rotation/revocation is performed by replacing or clearing the host key pair and rebuilding configuration cache; overlapping-key rotation has not been implemented.
- The exact final trusted signed Setup ZIP and Update ZIP, full supported install/upgrade and restore evidence, Composer/cPanel UID/ACL compatibility and owner-controlled host evidence remain outstanding. The Setup artifact from #2745 is unsigned CI staging only.
- Customer, Driver and Van have no direct API/runtime impact from the updater backend change; no app build PASS is implied. B2B=VAN ONLY and B2C=DRIVER ONLY remain unchanged.

`SOURCE_COMPLETE: NO` for full G005. `REVIEW_RISK: HIGH`. Keep PR #3968 draft and Issue #2746 open until implementation, exact-final-head checks, trusted artifacts and required delivery evidence are complete. No merge, main, release, tag, publishing, deployment or production update. Final manual UAT remains under #2503.

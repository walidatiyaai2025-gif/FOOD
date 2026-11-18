# V2 #2746 / G005 — cPanel PHP Sodium and upload-temporary prerequisites

**Owner:** existing V2 #2746 issue and PR #3968, base `Foodex_V2` only; no main, live cPanel, tags, release, migration or installer state. Risk HIGH; **NOT full G005 source acceptance**.

## Exact owner-scoped change

- Reuse existing Super Admin-only `/admin/settings/system-update` GET and `hostingPermissionChecks()`. Two additive **read-only** checks:
  - `sodium_signature`: PHP sodium is loaded and the Ed25519 detached verify primitive and public key constant exist. Returns boolean; does **not** read trust keys, validate any package, or authorize an update.
  - `upload_temporary`: effective PHP upload temporary directory (php.ini `upload_tmp_dir`, else PHP's default temporary path) passes original non-mutating writable-directory and symlink-ancestor check. Never creates a directory or writes a probe. This is **not** evidence that a multipart ZIP upload will succeed with real `open_basedir`, disk and PHP size limits.
- Reuses original `data-host-requirement` / `data-host-status` semantics (`precheck-ok` vs `host-action-required`), warning banner, existing file/backup/update locks and original `system.update`/SUPER_ADMIN authorization. No new route, Sidebar, system mutation, env storage or readiness override.
- Bilingual keys reside only in `backend/lang/ar/update_host_readiness.php` and `backend/lang/en/update_host_readiness.php` to avoid active #2858 PR #3065 ownership of global `admin.php` language files.
- Extends original `SystemUpdateAuthorizationTest` bilingual render/negative path, preserving live-sentinel non-mutation check. Real cPanel host permissions remain **UNVERIFIED** because CI is not the customer's host.

## Seed / initial configuration separation

- **G004 #2745 original PR #3444 owns actual installer and `DatabaseSeeder`; do not edit it from G005.** The actual initial install calls `DatabaseSeeder -> CoreReferenceSeeder`, with a guarded first-run-only step and fresh-install database check. It does not run the separate Dashboard demo/QA launcher by default.
- `CoreReferenceSeeder` reconciles canonical system role/permission values. Do **not** invoke it manually against live data: it deletes/rebuilds assigned baseline `permission_role` entries for every role in the configured built-in matrix. Fresh-install test should confirm system baseline, preservation of custom role/tenant data and editable business master data via existing admin form; production reseed is not acceptance evidence.
- ERP company/legal entity and other organization master records have existing create/update controllers and audit service, but no claim is made that arbitrary seeded business values or all ERP settings are automatically ready. Real business input, entitlement/certification and host grants must remain explicit and operator-managed.

## Security / failure matrix

- False sodium availability is a **host action requirement**, not a bypass; true sodium availability remains only a PHP runtime prerequisite because trusted publisher signature enforcement is **not yet wired** to the updater. Original prominent unsigned SHA-only blocker stays visible.
- Temp-dir missing or blocked: host action required; no `chmod 777`, no hidden fallback to a web-visible folder, no created probe/symlink acceptance.
- Wrong role/inactive actor: previous Super Admin-only route tests and middleware still apply; diagnostics do not introduce POST actions.
- Customer: NO_DIRECT_IMPACT; Driver: NO_DIRECT_IMPACT; Van: NO_DIRECT_IMPACT. No API/routes/mobile files, credentials or fulfillment contract changed.
- CI/QA: scoped feature test, syntax/Pint/PHPStan/localization, exact-final-head Repository Policy + V2 Isolation + Required CI must all finish successfully **before integration can even be considered**. Full G005 remains OPEN/DRAFT until signed-source and Update ZIP/backup/restore source acceptance and external operator cPanel verification exist.

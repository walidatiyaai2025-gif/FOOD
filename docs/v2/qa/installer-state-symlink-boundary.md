# V2 #3280 — Installer progress/lock symlink boundary (P112 / G004)

## Scope, original gap and source

Parent gate #2745, source/implementation umbrella #2846, master #2503. Integration **Foodex_V2 ONLY**. Original `InstallState` read and wrote `install-progress.json` and `installed.lock` directly; a symlink could redirect reads (including progress token and admin password hash) or produce misleading installer state. The independent `.env` protection from #3209 is preserved, not duplicated.

This atomic change denies symlinks for both final state files **and any existing ancestor**, including dangling links. Checks are performed before a read/state probe, before deletion, before directory preparation and again immediately before the atomic rename of a new state file. An observed symlink fails closed with `RuntimeException`. Normal progress token, step advancement, first-install lock and clear-progress behavior remain unchanged. No secrets or external file contents are returned.

## Placement / authorization / functional impact

- **Domain / actor:** Laravel first-run web installer. No business Dashboard screen, Sidebar node, API action, DB entity or role grant is added; the existing authorized setup wizard is reused.
- **Affected files:** `backend/app/Domain/Installer/InstallState.php` and dedicated `backend/tests/Feature/V2InstallerStateSymlinkBoundaryTest.php`; this QA note is the only documentation change.
- **Security boundary:** Progress token and Super Admin preparation are sensitive local installer state; linked paths must not be trusted. The existing application installer lock still closes the UI after successful installation.
- **Dashboard:** existing setup wizard's state access only; no new UI, navigation, translations or responsive styles.
- **Customer:** not affected; no app auth, endpoint, payload, store/channel or Flutter change.
- **Driver:** not affected; no B2C dispatch, payment or Flutter change.
- **Van:** not affected; no B2B Van assignment, visit or Flutter change.
- **AR/EN and RTL/LTR:** unchanged existing installer surface; one deterministic server-side exception contract, without introducing unlocalized labels.
- **Failure/recovery:** symlinked state aborts without following or deleting the target. An operator should restore the expected regular state file under the secured installation storage path after an authorized investigation; no automatic deletion of arbitrary linked targets.

## Tests

Run `cd backend && php artisan test --filter=V2InstallerStateSymlinkBoundaryTest`, and the full Laravel/Pint/static-analysis/security suite. Negative cases: linked existing progress file, dangling progress link, existing and dangling install lock, linked parent directory; neither external target is overwritten or created. Positive case: ordinary progress/token/lock/clear works. Symlink tests skip only on platforms where creating links is unsupported.

Require exact-final-HEAD GitHub `Repository Policy`, `FOODEX V2 Isolation Gate` and terminal `FOODEX Required CI Gate` success before SHA-guarded merge into **Foodex_V2 only**.

## Residual gate acceptance

This atomic security patch **does not certify** the complete #2745 signed production-dependency Setup ZIP, web-wizard cPanel install, exact ZIP MySQL fresh-install, artifact signatures or release. #2745, #2846 and #2503 remain open until independent package-level acceptance. No `main`, tag, release, staging or live deployment.

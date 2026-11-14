# V2 #3422 — localized Admin ZIP update success feedback

- Parent: #2746 G005, #2846 P112, #2503; integration **Foodex_V2 ONLY**.
- Existing route/navigation: `Settings → System → System Updates`, named `admin.system-update.store` POST and `admin.system-update.index` GET; permitted only with existing `system.update` gate. Reuse existing page's accessible `role="status"` success flash. Placement: **Reuse**, no new screen, Sidebar entry, mutation, field or component.
- Existing defect: `SystemUpdateController::store()` emits a literal English message on update success. It appears unchanged in the Blade `session('status')` region even for authenticated Arabic sessions.
- Resolution: only after the existing update manager succeeds, flash `__('admin.system_update_surface.update_completed', ['version' => $history->to_version])`. Both Arabic and English language catalogs declare the same key; user locale selects presentation, not update authorization or package behavior.
- Authenticated HTTP coverage: real `POST /admin/settings/system-update` request with a synthetic no-op `UpdateRuntime` avoids touching real files/databases while exercising controller, request validation, package hash, manager, history, redirect and flash; AR and EN exact strings, accessible `role="status"`, and unauthorized admin denial. No staging/production update is attempted.
- Source fence: `SystemUpdateController.php` success flash, one translation key in each `backend/lang/{ar,en}/admin.php`, new HTTP regression and this QA note. No open V2 PR file overlaps identified at claim.

## Authorization and application matrix

| Surface | Result |
| --- | --- |
| Dashboard System Updates | Reuse unchanged authorized route, form and status region; success feedback now locale-resolved; RTL/LTR handled by existing shell |
| Laravel UpdateManager | No pipeline, validator, backup, migration, cache, recovery or signing change in this issue |
| Customer | No Flutter/API contract change |
| Driver | No Flutter/API/dispatch contract change; B2C Driver-only |
| Van | No Flutter/API/custody/dispatch contract change; B2B Van-only |
| AR/EN | Paired `admin.system_update_surface.update_completed` key interpolates the canonical version |
| A11y / responsive | Existing `role="status"` no layout or DOM changes; accessible on existing desktop/mobile page |
| Security and tenancy | Existing `system.update` permission enforced before update; no scope widening |

## Verification

`cd backend && php artisan test --filter=V2SystemUpdateLocalizedFlashTest`

Full exact-head PR gates: Repository Policy, FOODEX V2 Isolation, FOODEX Required CI (Laravel/Pint/PHPStan/Composer security audit, localization AR/EN, MySQL/Redis install/upgrade/recovery). These are **required**, not assumed passed from the presence of the tests.

Manual browser/staging/UAT is `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` per owner #2503; this does not waive automated source or security acceptance. No main merges, tags, release artifacts, cPanel or production changes.

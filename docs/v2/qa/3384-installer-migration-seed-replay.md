# V2 #3384 — completed migration and seeder installer step replay

## Authority
Parent G004/#2745 and P112/#2846, master #2503. **Integration exclusively `Foodex_V2`**. Contract: `AGENTS.md`, `docs/v2/V2_ISOLATION_GOVERNANCE.md`; prior atomic #3369 APP_KEY completed-step replay is already merged and must not be duplicated.

## Defect and lifecycle
The existing `POST /install/step/{step}` accepts a valid token for any completed step (as well as its immediate successor). Before this patch, step 11 always executed `migrate --force` and step 12 always executed `db:seed --force`, including on a completed-step replay. A browser retry may rerun data-dependent migrations or reseeding after later installer work.

The controller now checks `progress.completed_step < 11` before step 11's migration and `progress.completed_step < 12` before step 12's seeding. First attempts execute normally; failed runs do **not** advance progress and are therefore retryable. A completed-step replay returns the same transition without redoing database writes. Installer token guard, sequential step access, existing site configuration flow, APP_KEY guard from #3369 and final installation lock are unchanged. There are no new translations.

## HTTP regressions
`backend/tests/Feature/V2InstallerCompletedDatabaseStepReplayTest.php` uses real installer HTTP POSTs with test-scoped `.env`/progress and Laravel `Artisan` execution spies:
- First migration invocation once, then completed-step replay makes no further call.
- Failed migration (exit=1) returns 422 and leaves progress unchanged; successful retry proceeds, subsequent repeat performs no migration.
- First seed invocation once, then change to an initially seeded admin-editable flag is preserved through replay.
- Failed seed is retryable; successful retry prevents later duplicate invocation.
- Invalid installer token (419) and out-of-order step request (409) do not call Artisan or advance progress.
- Existing first-install and Setup ZIP MySQL acceptance remain required via exact-head CI.

## Impact classification
| Surface | Impact / evidence |
|---|---|
| Backend | Only the installer controller replays for steps 11/12; no new API or authorization route. |
| Dashboard / Web Installer | Existing install wizard forms/navigation preserved; side effects suppressed on completed database steps, as an idempotent POST. No layout/RTL/AR/EN change. |
| Customer Flutter | No source or public Customer API touched. |
| Driver Flutter | No source or public Driver API touched; B2C Driver dispatch invariant preserved. |
| Van Flutter | No source or public Van API touched; B2B Van only invariant preserved. |

## Coordination and CI
Files owned only: `backend/app/Http/Controllers/Installer/InstallerController.php` and new test/QA doc. `InstallerWorkflow.php` (#3367), `InstallerEnvironment.php` (#3372), `InstallState.php` (#3378), Update ZIP runtime and all Flutter code remain untouched.

Merge only through one PR to `Foodex_V2` with **exact-head** successful Repository Policy, FOODEX V2 Isolation and Required CI Gate including Laravel/Pint/PHPStan/security, AR/EN and real MySQL/Redis install/upgrade/recovery. This atomic fix is not Setup ZIP production certification. Parents #2745/#2846 and #2503 stay open; no main/tag/release/deployment.

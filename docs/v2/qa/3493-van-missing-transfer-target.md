# V2 #3493 — Missing Van transfer-target validation under deterministic row locks

Source: P276; parent #2738 / #2606 / #2503. Follow-up to merged #3491 / PR #3492. Base and only allowed integration: **Foodex_V2**. No main merge, releases, tags, APKs, cPanel or production.

## Source-level defect
The existing Van suspension service accepts a previously loaded destination Van model. If that target was deleted before the service can acquire its lock, the old lock loop used `findOrFail()` for source *and* target. That throws a generic `ModelNotFoundException` before the loaded-work validation that promises the localized `transfer_target` field error for an absent, stale, self or inactive destination. With no loaded work, an optional stale target also prevented normal Van suspension. No actual production incident is inferred.

## Reuse and bounded fix
Keep `VanRegistryService::suspend()`, its single DB transaction, ascending numeric Van-ID row-lock order and assignment locks afterward. Only the source uses `findOrFail()`; a missing optional target is recorded as `null` by `find()` under the same row-lock query. The existing `$loaded > 0` authorization then rejects that target with `van_assignment_guard.active_transfer_target_required`. No source/assignment/ledger/audit write occurs on this failed validation. Unloaded Van suspension ignores a missing optional destination and retains its previous no-transfer behavior. Existing #3487 historical/future assignment time boundaries, #3489 assignment constraints and #3433 B2B Driver guard remain unchanged.

## Automated verification
- `V2VanMissingTransferTargetGuardTest`: stale/deleted destination with loaded assignment rejected as a localized AR/EN `transfer_target` ValidationException, no assignment/status/loaded-count/transfer/date change
- Deleted optional destination with unloaded assignment: allowed suspension, no bogus transferred ID
- Deleted source: still `ModelNotFoundException`; no accidental authorization expansion
- Distinct active target for loaded work: success and expected transfer provenance
- Existing `V2VanSuspensionTransferTargetGuardTest` and `VanRegistryServiceTest` remain in full Laravel CI
- Exact final-head CI must prove Repository Policy, FOODEX V2 Isolation Gate and Required CI Gate terminal SUCCESS, including Laravel/security/Pint/PHPStan/AR-EN and MySQL/Redis fresh install/upgrade/recovery. No test outcomes claimed before Github Actions reports them.

## Route / scope / mobile impact
| Surface | Status |
| --- | --- |
| Backend | Affected: validation path of existing `VanRegistryService::suspend` |
| Dashboard | Existing server-authorized suspension workflow receives same translated field error; no view/controller/nav route change |
| API | No new endpoint, payload, role, permission, entitlement or schema |
| Customer | No Customer Flutter or public API change; B2B stays VAN ONLY |
| Driver | No Driver Flutter, API or B2C dispatch change; B2C stays DRIVER ONLY |
| Van | No Van Flutter/API schema change; existing B2B suspension destination validation is fail-closed |

No new business UI: canonical Field Operations action reused; no side-navigation or AR/EN translation keys changed. Controlled AR/EN errors are already provided by #3491. The broader actual custody, physical transfer, stock, settlement and mobile field lifecycle remain open under #2607/#2739/#2738/#2503; they are not claimed here.

## Acceptance and release fence
One claimed issue / branch / PR; review changed files for non-overlap, exact-head CI green, SHA-guarded merge **only to Foodex_V2**, then close #3493 `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED` with tested SHA, integration SHA and CI links. Master #2503 retains final manual/staging acceptance; nothing authorizes main/production promotion.

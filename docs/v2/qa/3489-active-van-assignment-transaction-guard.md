# V2 #3489 — Active Van assignment transaction guard

Parent #2738 MOB-V02, #2606 P276 and master #2503. Source defect independently audited on Foodex_V2 before creating issue-scoped work. Target exclusively `Foodex_V2`; no main, production, deployment, tags, releases or APK publishing.

## Reproduction and authority

`VanRegistryService::assign()` previously inserted an `active` assignment without checking `vans.status`; a `suspended` Van could receive newly active assignments through the authorized Dashboard service call. `updateAssignment()` checked active status *before* the transaction and performed the write after the check without serializing on the Van row. Concurrent `suspend()` uses Van-first row locks and ends assignments, so a racing assignment write could violate the suspension invariant. This documents a code-path/race risk, not a confirmed live incident.

## Fix

- Inside both assignment write transactions, lock the canonical Van row with `lockForUpdate()`, then reject any missing/inactive/suspended Van before assignment/audit writes. No status-cache or client-side authority; use Van-first lock order identical to `suspend()`.
- Preserve the current create/edit request shapes, employee Driver B2B eligibility, permitted representative-only Van operations, existing overlap tests, store/RBAC/tenant checks, effective periods and audit. No direct UI layout/Sidebar/API or database migration change.
- Localized field-specific `van_id` error is supplied in `backend/lang/en/van_assignment_guard.php` and `backend/lang/ar/van_assignment_guard.php`; selected locale controls the displayed validation text.

## Verification

`backend/tests/Feature/V2ActiveVanAssignmentStatusGuardTest.php` uses RefreshDatabase and the real VanRegistryService: inactive create denial and zero audit side effects; active→suspended edit denial, no partial mutation; suspend→create/reactivate denial; successful active Van create/update (representative-only) and audit; English/Arabic message parity. Existing VanRegistryServiceTest and VanAssignmentManagementTest must remain green. The live CI gate must prove PHP/Pint/PHPStan/Composer security/OpenAPI, full Laravel backend, AR/EN localization and real MySQL/Redis deployment acceptance on exact final HEAD; GitHub Actions URLs/SHAs appended in issue/PR evidence.

## Surface classification

| Surface | Classification |
| --- | --- |
| Backend | Affected: canonical Van assignment create/update server-side invariant |
| Dashboard | Existing authorized assignment forms receive localized validation error; no route, form, navigation or permission changes |
| API | No changed response schema/endpoint; existing Dashboard form validation exception only |
| Customer | No Flutter or API change, B2B orders remain Van-only |
| Driver | No Flutter/driver dispatch change, B2C remains Driver-only |
| Van | No Flutter/API contract change, inactive Van cannot receive active assignment; MOB-V02 #2738 remains broader owner |

No signed ZIP, owner staging UAT, Android provenance or full Van mobility readiness is claimed. Those are tracked by #2738/#2606/#2503. This issue is atomic source-level protection, eligible for closure only on reviewed Foodex_V2 PR merge and all exact-final-HEAD required CI green.

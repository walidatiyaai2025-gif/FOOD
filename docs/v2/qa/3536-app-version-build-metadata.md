# #3536 — V2 app-version SemVer build metadata precedence

Source: V2 cross-app contract #2740, parent acceptance #2503. Base and integration target: **Foodex_V2 only**. No main, release, cPanel or production.

## Observed source defect and regression
`AppVersionPolicy::normalize` validated plus-suffixed build metadata but supplied it directly to PHP `version_compare`. PHP compares `1.0.0+5` as older than `1.0.0+6`; SemVer build metadata must not affect precedence. Existing values may therefore lead to an incorrect forced or optional mobile update at an otherwise identical release. This is source-level diagnosis only, **not** evidence of any live affected user.

## Minimal implementation
- Keep the existing normalized numeric-version shape and optional `v/V` prefix.
- Permit conventional `-prerelease+build` combinations, rejecting malformed metadata, while ignoring the validated `+build` section **only for comparisons**.
- Keep raw `latest_version`, `minimum_supported_version`, `store_url`, `release_notes` and all current JSON keys/HTTP semantics unchanged. Real older versions and prereleases still force/require updates according to the configured policy.
- No authorization, tenant/store/channel, write, migration, route or Dashboard navigation change.

## Automated acceptance
`backend/tests/Unit/V2AppVersionBuildMetadataTest.php` covers (1) forced-update policy at equal version with different metadata remains `current`, no update; (2) raw response versions preserved; (3) older and release-candidate versions remain appropriately forced/unsupported; (4) administrative minimum/latest ordering ignores metadata but rejects a genuinely higher minimum; (5) malformed versions still reject. Existing `AppVersionPolicyTest` HTTP and Dashboard policy management tests remain authoritative for policy request/response contract and authorization.

CI evidence to attach on exact final PR HEAD: Repository Policy, FOODEX V2 Isolation, Required CI (Laravel including focused test, Pint, PHPStan, security, AR/EN, applicable MySQL/Redis). Do not claim CI/merge until terminal results confirmed.

## Per-surface impact / isolation
| Surface | Impact | Evidence |
| --- | --- | --- |
| Backend/API | **Affected** version policy evaluation flags only | Same AppVersionPolicy service, no route/schema/error changes |
| Customer Flutter | **Affected behavior**, no client source change | Existing Customer version-policy gate interprets corrected `status/update_required/force_update` flags; no new fields |
| Driver Flutter | **Affected behavior**, no client source change | Existing Driver version-policy gate uses same keys |
| Van Flutter | **Affected behavior**, no client source change | Existing Van app version policy uses same API status contract |
| Dashboard | Existing version-policy minimum/latest order corrected | No view/navigation, AR/EN label or route change |
| Dispatch/finance | **Unaffected** | B2B=VAN ONLY, B2C=DRIVER ONLY preserved |

No new visible string was introduced; AR/EN/RTL/LTR presentation is unchanged. Parent #2740/#2503 must remain OPEN for full three-app UAT and V2 acceptance. The atomic leaf can close only after all source/test/CI requirements pass and the PR is verified merged into `Foodex_V2`.

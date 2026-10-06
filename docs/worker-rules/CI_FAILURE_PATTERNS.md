# FOODEX Known CI Failure Patterns

This registry records reusable failure classes. It is prevention guidance, not a bypass list.

| Failure class | Early detector | Prevention |
| --- | --- | --- |
| Flutter lazy-list widget assertion reports 0 matches for off-screen content | Widget test asserts text/key inside a lazy `ListView` before that child is built in the viewport. | Scroll the owning `Scrollable` until the target key is visible before asserting; for screens with polling timers, use bounded `pump()` calls instead of `pumpAndSettle()`. |
| Invalid Issue branch name | repository preflight | Validate branch before first edit/push. |
| PHP/Pint style drift | `composer lint` | Run formatter-generated canonical output before full tests. |
| Worker preflight exits before changed-area lint because `changed_files` is referenced before initialization | `bash -n scripts/worker-preflight.sh` + fast preflight smoke | Initialize diff-derived variables before release routing; keep `set -u` enabled so ordering bugs fail locally, not in remote CI. |
| Multiline PHP brace/signature mismatch | Pint on affected file | Use repository/Pint canonical signature layout; do not guess braces. |
| PHP syntax damage from scripted edits | `php -l` changed files | Syntax-check every mechanically edited PHP file. |
| PHP static type drift | `composer analyse` | Run PHPStan before the full Laravel suite. |
| Flutter import/type/nullability errors | `flutter analyze` | Analyzer runs in fast gate before platform builds. |
| Stale Flutter test assumptions | focused Flutter tests | Reconcile test contract with authoritative runtime/API semantics. |
| Backend workflow test skips an authoritative state transition | focused service/feature state-machine test | Drive tests through every required transition in the canonical order; do not jump directly to a later status just to reach the assertion under test. |
| APP-PREVIEW branch-scope violation | preview scope guard | Check allowed scope before preview build/parity. |
| Preview backend contract/security mismatch | contract tests | Validate producer/consumer/security contract before runtime build. |
| Embedded vs standalone preview mismatch | parity smoke | Reuse exact head runtime and preserve navigation/state contract. |
| UI visual runtime failure | runtime health smoke | Verify route/data/console health before screenshot capture. |
| MySQL/MariaDB migration rollback mismatch | DB acceptance | Validate forward/rollback behavior on supported DB engine. |
| Parallel same-domain implementations carry incompatible schema assumptions into one PR | full backend tests after integration-train reconciliation | Before merging concurrent lane work, choose one canonical domain schema/service/API contract and remove superseded competing implementation/tests/routes; do not preserve both just because each passed independently. |
| Nested Required CI child red while top-level checks look green | required gate child inspection | Read all applicable child jobs; one red means not ready. |
| New mobile app path is absent from Required CI changed-area detection | `required-ci-gate` may look green while app analyzer/tests never run | Wire every first-class mobile app into a dedicated reusable CI workflow and the Required CI detector/gate before the lane can be considered complete. |
| Main branch drift | exact-head/base comparison | Revalidate against current main before readiness/merge. |
| Stale release artifact | release-registry validation + SHA manifest/checksum | Dashboard update `target_version` must equal `VERSION`, and manifest/checksum/registry SHA-256 values must match the exact package bytes on the validated head. |
| Repeated identical CI failure across pushes | failure fingerprint | Reproduce locally before another push; promote rule/check. |
| Dependency/lockfile accidental drift | diff preflight | Reject unrelated lockfile changes and implicit upgrades. |
| Flaky time/random/order test | deterministic test setup | Freeze clock/seed/state; do not rerun-until-green. |

| Main push fails after green PR because deployable code changed without VERSION bump | `scripts/validate-premerge-release-version.sh` | Mirror main-push release/version preconditions before merge; bump VERSION and synchronize Customer/Driver identities. |

| Partial release identity sync: VERSION/pubspec updated but Customer/Driver runtime `_appVersion` remains previous release | `bash scripts/release-readiness.sh` | Treat version bump as atomic; synchronize root, mobile build, visible/runtime, notes and changelog identities before push. |
| Release readiness exits silently with no invariant name | named assertion output in release readiness | Release validators must print the exact failed invariant/value pair so workers can fix first-pass failures quickly. |
| Release validator hard-codes one production origin/database while an approved release train uses another documented production target | `bash scripts/release-readiness.sh` against the release branch | Read the non-secret production origin/database/username from `backend/.env.production.example`, require documented configuration parity, and require Customer/Driver runtime defaults to match that same origin; do not weaken secret/signing checks. |

| Eloquent datetime/JSON cast runtime works but Larastan still infers raw scalar column types | `composer analyse` on changed models/controllers | Expose typed accessors/normalizers for cast values consumed by business logic and use those typed methods instead of calling date/array operations directly on dynamic model properties. |
| Android AAB signing fails with `Get Key failed: Given final block not properly padded` after an ephemeral CI keystore is generated | store-readiness/release AAB validation | Do not rely on the JDK default PKCS12 behavior with different store/key passwords. Generate an explicit JKS (or use one password for store/key), and verify release signing never falls back to debug. |
| Partial release publication: deployable VERSION bump is not paired with the exact update bundle, manifest/checksum and one append-only release-registry entry | `scripts/validate-repo.sh` + update-bundle validation | Treat a release bump as one atomic publication unit: generate the exact-version deterministic update bundle, commit its matching manifest/checksum/file list, append exactly one immutable registry entry with the exact package SHA, then validate the complete set. |
| Android release identity label drifts after `flutter create` | Release APK badging check fails after package validation | Native configuration script must explicitly patch `android:label` from the canonical app identity; do not assume the generated project inherits the product label. |
| Flutter symbol compiles conceptually but analyzer reports `creation_with_non_type` after importing a wrapper module | `flutter analyze` on changed app before tests | Dart imports are not re-exported transitively: import the file that owns the exception/model type explicitly; also replace analyzer-reported deprecated Flutter form APIs before pushing. |
| Cross-lane typed client/test still asserts pre-integration reason codes after canonical dependency lands | Focused client contract test fails while analyzer passes | Re-read the merged canonical service contract and update client reason-code enums/tests to the runtime names; never preserve scaffold aliases after dependency integration. |
| Trial Distribution red on normal main feature merge with unchanged VERSION | release-intent detector | Do not treat deployable-path changes alone as release intent; skip distribution cleanly unless VERSION changed or manual distribution was requested. |
| Repository Policy rejects every child PR targeting a release integration branch whose current version is an inherited `release-candidate` | `.github/scripts/release-registry.test.js` + `scripts/validate-repo.sh` on the actual PR base | Permit only the unchanged current `release-candidate` inherited from a non-main `release/*` base; all earlier entries remain `published`, the candidate entry is immutable within the child PR, and `main` validation remains published-only. |

| Worker Watchdog API-rate-limit storm from broad event scans / duplicate workflow_run triggers | Watchdog event-target unit tests + GitHub rate-limit classification | Reconcile only the event-owned Issue/PR outside scheduled sweeps; listen to the aggregate Required CI Gate once; suppress watchdog-authored comment recursion; treat exhausted installation quota as deferred infrastructure pressure, never branch CI red. |

When a new pattern qualifies under the Recurring Failure Promotion Rule, add it here with its cheapest reliable early detector.

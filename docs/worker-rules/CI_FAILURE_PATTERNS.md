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
| APP-PREVIEW branch-scope violation | preview scope guard | Check allowed scope before preview build/parity. |
| Preview backend contract/security mismatch | contract tests | Validate producer/consumer/security contract before runtime build. |
| Embedded vs standalone preview mismatch | parity smoke | Reuse exact head runtime and preserve navigation/state contract. |
| UI visual runtime failure | runtime health smoke | Verify route/data/console health before screenshot capture. |
| MySQL/MariaDB migration rollback mismatch | DB acceptance | Validate forward/rollback behavior on supported DB engine. |
| Parallel same-domain implementations carry incompatible schema assumptions into one PR | full backend tests after integration-train reconciliation | Before merging concurrent lane work, choose one canonical domain schema/service/API contract and remove superseded competing implementation/tests/routes; do not preserve both just because each passed independently. |
| Nested Required CI child red while top-level checks look green | required gate child inspection | Read all applicable child jobs; one red means not ready. |
| New mobile app path is absent from Required CI changed-area detection | `required-ci-gate` may look green while app analyzer/tests never run | Wire every first-class mobile app into a dedicated reusable CI workflow and the Required CI detector/gate before the lane can be considered complete. |
| Main branch drift | exact-head/base comparison | Revalidate against current main before readiness/merge. |
| Stale release artifact | SHA manifest/checksum | Artifacts must identify and match final validated head SHA. |
| Repeated identical CI failure across pushes | failure fingerprint | Reproduce locally before another push; promote rule/check. |
| Dependency/lockfile accidental drift | diff preflight | Reject unrelated lockfile changes and implicit upgrades. |
| Flaky time/random/order test | deterministic test setup | Freeze clock/seed/state; do not rerun-until-green. |

| Main push fails after green PR because deployable code changed without VERSION bump | `scripts/validate-premerge-release-version.sh` | Mirror main-push release/version preconditions before merge; bump VERSION and synchronize Customer/Driver identities. |

| Partial release identity sync: VERSION/pubspec updated but Customer/Driver runtime `_appVersion` remains previous release | `bash scripts/release-readiness.sh` | Treat version bump as atomic; synchronize root, mobile build, visible/runtime, notes and changelog identities before push. |
| Release readiness exits silently with no invariant name | named assertion output in release readiness | Release validators must print the exact failed invariant/value pair so workers can fix first-pass failures quickly. |

When a new pattern qualifies under the Recurring Failure Promotion Rule, add it here with its cheapest reliable early detector.

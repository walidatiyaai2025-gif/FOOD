# FOODEX First-Run Green Contract

This contract defines how workers must prepare changes so the first remote CI run is green as often as practically possible.

The goal is not to weaken CI. The goal is to move deterministic failure discovery to the worker's local/pre-push phase and make remote CI fail fast before expensive builds.

## Required worker sequence

Every coding task follows this sequence:

1. inspect the Issue, existing branch/PR, current head, current main and current CI;
2. establish a clean baseline and identify pre-existing red state;
3. determine changed/risk areas before editing;
4. implement the smallest safe change;
5. run mechanical autofix tools instead of guessing formatter output;
6. run the changed-area fast preflight;
7. run affected focused tests and contract checks;
8. run runtime/visual/migration smoke checks when the change requires them;
9. review the final diff for scope, secrets, debug code, stale generated files and hardcoded business data;
10. push only after the local fast preflight is green;
11. before merge readiness, run the full affected preflight and validate the exact final head.

Canonical local commands:

```bash
bash ./scripts/worker-preflight.sh --fast
bash ./scripts/worker-preflight.sh --full
```

The fast command is the minimum pre-push contract. The full command is the readiness contract when the required tools and environment are available.

## Fast-fail ordering

Remote CI should discover failures in this order whenever applicable:

1. repository/branch policy;
2. diff integrity and syntax;
3. formatter/lint;
4. static analysis/analyzer;
5. focused/unit/widget tests;
6. full subsystem tests;
7. database/service acceptance;
8. platform compilation/builds;
9. preview/runtime parity;
10. visual evidence;
11. packaging/release validation;
12. exact-head final required gate.

Expensive work must depend on cheaper deterministic validation where practical.

## Backend rules

For backend changes, formatter and static analysis run before the full Laravel suite.

Required fast checks include:

- `composer validate --strict --no-check-lock`;
- PHP syntax checks for changed PHP files;
- `composer lint` / Pint;
- `composer analyse` / PHPStan.

Workers must never manually guess a Pint fix when Pint can generate the canonical form. Run Pint on the affected file, then verify it with `--test`.

Known PHP formatting traps include brace position, multiline method signatures, constructor formatting, imports, quote style, PHPDoc and operator spacing.

## Flutter rules

For each affected Flutter app, the fast lane runs before release builds:

- dependency resolution;
- `flutter analyze`;
- affected test suite.

Android release build, iOS no-codesign build, preview Web build, parity and packaging are downstream heavy validation and should not consume runners when analysis/tests are already red.

Do not run `flutter clean` by default. Preserve useful dependency/build caches unless a real cache invalidation defect requires cleaning.

Customer-only changes must not trigger Driver validation unless shared code/contracts are affected, and vice versa.

## Changed-area and risk routing

Validation is selected from the actual git diff, not worker intuition.

High-risk changes expand validation automatically. High-risk areas include:

- authentication / authorization / CSRF;
- tenant, customer, store and IDOR isolation;
- pricing, currency, credit, ledger, invoice and payment semantics;
- migrations, seeders and recovery;
- shared Flutter packages and networking;
- preview/runtime contracts;
- release/update packaging.

A small UI-only change should not trigger unrelated expensive areas, but a shared contract change must validate every consumer.

## CI acceleration rules

- New commits supersede older runs on the same PR; obsolete runs should be cancelled.
- Cache keys must be dependency/toolchain based, not merely branch based.
- Pin Flutter, Dart, PHP, Node, Java/Gradle and other toolchains used by CI.
- Reuse built artifacts across downstream jobs where safe; do not rebuild identical runtimes repeatedly.
- Runtime/release artifacts must be labeled with the exact commit SHA.
- Never reuse a stale executable artifact as evidence for a newer head.
- Retry only genuinely transient network/setup failures; never retry deterministic assertions until they happen to pass.
- Independent fast checks may run in parallel; expensive dependent checks wait for the fast gate.
- Long test suites may be sharded only when isolation is proven and result aggregation remains authoritative.

## First-run green quality controls

Before push, workers must also check:

- `git diff --check`;
- no accidental debug flags, commented security checks or temporary bypasses;
- no secrets or credentials;
- no hardcoded customer/store/account/product IDs or fixed business currency where runtime/store data is authoritative;
- no unintended lockfile/dependency drift;
- generated files are current and not hand-edited incorrectly;
- API/model changes preserve producer/consumer contracts;
- migrations are safe for supported MySQL/MariaDB behavior and rollback policy;
- role/permission and tenant boundaries remain intact;
- AR/EN and RTL/LTR behavior is checked for changed user-facing surfaces when applicable;
- smallest supported mobile layout/text scaling is considered for Flutter UI changes;
- routes/actions remain reachable through intended navigation.

## Failure fingerprint and promotion

A deterministic failure that repeats, or a one-off failure that reveals a stable repository constraint, must be promoted into prevention.

The worker must:

1. fix the root cause;
2. record the failure class and reproduction command;
3. update AGENTS.md or this contract if the rule is reusable;
4. automate detection in preflight/CI when cheap and deterministic;
5. add a regression test when the failure represents application behavior;
6. never disable a required gate merely to remove red status.

Repeated pushes with the same failure fingerprint are a process defect. The worker must reproduce locally or obtain new evidence before pushing another attempted fix.

## Flaky test policy

A test that changes result without a relevant code/environment change is not fixed by rerunning until green.

It must be diagnosed as flaky, with the nondeterministic dependency identified (clock, random seed, network, ordering, external service, shared state, etc.) and corrected or explicitly isolated under repository-owner-approved policy.

## Metrics

CI optimization should track:

- time to first deterministic failure;
- first-run green rate;
- repeated-red pushes with the same fingerprint;
- runner queue/setup time;
- dependency installation time;
- test/build time by area;
- compute spent on jobs that were unnecessary after an earlier deterministic failure.

The target is high first-run-green rate and near-zero repeated-red pushes, without reducing required acceptance coverage.

## Exact-head rule

A PR is ready only when required evidence belongs to its current head SHA.

If main advances in a way that can affect the task, the existing branch must be updated/revalidated before merge.

Packaging, APKs, preview runtimes and update bundles must come from the final validated head.

## Post-merge main parity

Green PR checks are not sufficient if additional deterministic workflows run only after a push to `main`.

Before merge readiness, workers must reproduce the deterministic preconditions of applicable main-push workflows. For deployable FOODEX changes, run:

```bash
bash scripts/validate-premerge-release-version.sh <base-sha> <head-sha>
```

Deployable changes under `backend/`, `apps/customer_app/`, or `apps/driver_app/` require a `VERSION` bump relative to the PR base, and Customer/Driver mobile version identities must remain synchronized with `VERSION`.

Any post-merge failure that was predictable from the PR diff must be converted into a pre-merge check so it cannot recur as a main-only surprise.

## Atomic release identity preflight

Release branches must synchronize all release identities before the first push. A version bump is not complete when only `VERSION` and mobile `pubspec.yaml` files changed.

The minimum release preflight is:

```bash
bash scripts/release-readiness.sh
bash scripts/validate-premerge-release-version.sh <base-sha> <head-or-WORKTREE>
```

Customer and Driver visible/runtime version constants, mobile build identities, release notes and CHANGELOG must agree with the root release identity. Partial synchronization is rejected before expensive release packaging.

## Main-push release-intent parity

A normal feature/bug merge may update deployable runtime code without immediately publishing a new FOODEX release.

Release/distribution workflows must use explicit release intent:

- VERSION changed relative to the authoritative base; or
- manual distribution was explicitly requested.

If neither condition is true, the workflow should perform only a lightweight release-intent check and finish successfully with distribution skipped. It must not produce a red main build simply because runtime paths changed.

When VERSION changes, all release identities and immutable publication checks remain mandatory.

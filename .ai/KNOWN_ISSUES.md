# FOODEX Known Risk Areas

This file records recurring structural hazards and reusable lessons. It is **not** the live bug tracker. Query GitHub for current bugs, status and ownership.

## K-001 — Stale execution context can duplicate work
**Risk:** Old chat/context may point to an obsolete branch, PR or mission state.
**Guard:** Run the `AGENTS.md` preflight and reuse the existing Issue/branch/PR.

## K-002 — Generic green CI can be mistaken for product completion
**Risk:** A requirement can be visually or interactively wrong while generic checks pass.
**Guard:** Match evidence to the requirement; visual/interaction requirements need real integrated runtime evidence when the plan requires it.

## K-003 — Localization scanners can generate false positives
**Risk:** Blade JavaScript/CSS syntax can be misclassified as untranslated visible UI.
**Guard:** Real locale bug -> fix product. Parser bug -> fix scanner + regression test. Do not silence recurring parser defects broadly.

## K-004 — Release lineage can drift
**Risk:** Dashboard update, APKs, Setup ZIP, manifests or fresh-install evidence can come from different source commits/versions.
**Guard:** Enforce `docs/release/RELEASE_ARTIFACT_CONTRACT.md`; verify version, source SHA, checksums and three-app parity.

## K-005 — UI can leak database concepts
**Risk:** Business screens may expose raw IDs, keys, JSON or generic CRUD navigation.
**Guard:** Apply Dashboard UI/UX contract and classify fields as Lookup, Enum, Builder, legitimate free input or restricted advanced input.

## K-006 — Client state can diverge from backend authority
**Risk:** clients may infer assignment/order/finance state or trust stale deep links/push.
**Guard:** Resolve authoritative records/permissions server-side and make transition APIs idempotent where retries are expected.

## K-007 — Multi-record cleanup can damage audit/finance history
**Risk:** delete/reassignment features can accidentally remove core order, customer or ledger history.
**Guard:** Define purge ownership/scope, transact related writes, preserve immutable audit/ledger records and test cross-assignment/store isolation.

## K-008 — Local/remote CI routing can drift
**Risk:** The worker can validate one set of apps/tests locally while Required CI selects another set remotely.
**Guard:** `.ci/ci-map.json` is the single routing authority; both local preflight and Required CI use `scripts/ci-plan.py`, with drift regression tests.

## K-009 — Test network/toolchain nondeterminism can create false red
**Risk:** Unit/widget tests or mutable CI tools may depend on external network availability or silently change behavior between pushes.
**Guard:** Keep Flutter tests transport-hermetic, pin mutable CLI/action generations, classify recurring failures in `.ci/failure-patterns.json`, and never rerun deterministic red state unchanged.

Only add reusable risks here. Put concrete live defects in GitHub.

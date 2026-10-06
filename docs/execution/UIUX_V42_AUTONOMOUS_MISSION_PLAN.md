# FOODEX UI/UX v4.2 Autonomous Mission Plan

Mission ID: `UIUX-V42`  
Umbrella: **#1001 — [MISSION][ACTIVE] FOODEX UI/UX v4.2 autonomous completion**  
Integration target: `release/1.0.58-van-complete`  
Maximum active implementation lanes: **6**

## Goal

Execute the complete current FOODEX UI/UX/mobile-runtime plan with minimal repository-owner intervention. GitHub is the durable source of truth; ChatGPT sessions are disposable executors.

The owner should be able to open a new ChatGPT session and say only:

- `حرك مشروع FOOD`
- `اشتغل على مشروع FOOD`
- `FOOD MISSION`

The worker must discover #1001 from live GitHub state and continue the Mission/Drain loop.

## Non-negotiable execution model

- One child Issue = one canonical branch = one PR.
- Never create a retry/final/v2/replacement branch for an existing child.
- Re-read GitHub before every branch/PR creation and after any uncertain connection failure.
- If the branch already exists, reuse it.
- If the PR already exists, continue it.
- Red CI, test/lint/build failures, conflicts, drift and missing code are repository work, not human blockers.
- Running CI on the latest head is preserved; do not duplicate reruns.
- Push coherent checkpoints before long waits.
- A lost ChatGPT connection loses only the session, never project state.
- No merge or auto-merge to `main` from this Mission.
- Parent #1001 is coordination-only and gets no implementation branch.

## Authoritative product contracts

- `docs/design-reference/DASHBOARD_UI_UX_CONTRACT.md`
- `docs/quality/LOCALIZATION_CONTRACT.md`
- `docs/release/RELEASE_ARTIFACT_CONTRACT.md`
- `AGENTS.md` Section 20 Mission/Drain semantics.

## Child matrix

| Issue | Canonical branch | Lane | Dependency |
|---|---|---|---|
| #1002 | `test/1002-uiux-v42-mobile-ux-guard` | Automated mobile UX acceptance guard | Ready |
| #1003 | `feat/1003-customer-v42-mobile-compliance` | Customer compact/full-width/live-data compliance | Ready |
| #1004 | `feat/1004-driver-v42-mobile-live-orders` | Driver compact/full-width/live-order alerts | Ready |
| #1005 | `feat/1005-van-v42-mobile-compliance` | Van compact/full-width/live field operations | Ready |
| #1006 | `feat/1006-three-app-biometric-login-parity` | Three-app Remember Me/biometric/login identity | Ready |
| #1007 | `feat/1007-unified-driver-van-live-tracking` | Unified Dashboard Live Tracking | Ready; reuse #512 |
| #1008 | `feat/1008-customer-real-invoice` | Customer real invoice | Ready |
| #1009 | `ci/1009-release-artifact-contract-audit` | Release artifact automation acceptance | Ready; audit existing implementation |
| #1010 | `test/1010-localization-data-parity-audit` | Final localization/bilingual-data audit | After #1003-#1008 |
| #1011 | `test/1011-v42-cross-app-regression` | Cross-app integrated regression | After #1002-#1010 |
| #1012 | `test/1012-v42-final-convergence` | Final code convergence | After #1011 |
| #1021 | `release/1021-uiux-v42-final-real-build` | Final real release + clean Setup build | After #1012 PASS |

## Fastest-safe first wave

When 5-6 workers are started simultaneously, prefer these six disjoint/controlled lanes first:

1. #1003 Customer app
2. #1004 Driver app
3. #1005 Van app
4. #1006 Auth/biometric parity
5. #1007 Unified Dashboard tracking
6. #1008 Customer invoice

File fences in each Issue prevent Customer/Driver/Van lanes from editing auth-owned files, and the Customer lane excludes invoice-owned files.

As a slot becomes free, take #1002 and #1009. Do not exceed six active implementation lanes merely because more workers exist.

## Dependency unlock

- #1010 becomes actionable after the implementation lanes that can introduce localized UI/data are integrated: #1003, #1004, #1005, #1006, #1007 and #1008.
- #1011 starts only after #1002-#1010 are complete/integrated as required.
- #1012 starts only after #1011 passes and freezes/converges the accepted implementation into `release/1.0.58-van-complete`.
- #1012 records the exact final implementation source SHA and unblocks #1021.
- #1021 starts only after #1012 PASS and is the terminal real-release branch.
- #1001 closes only from #1021 after the complete mission matrix is green **and** the terminal real release build requirement below passes.

## Connection-loss / crash-safe protocol

A worker must checkpoint GitHub after every coherent unit and before any long wait.

Checkpoint must make these facts recoverable without chat history:

- Issue number;
- canonical branch;
- PR number when created;
- exact HEAD;
- CI state;
- completed acceptance items;
- unresolved repository-local defect;
- exact next action.

If a connection fails during a GitHub mutation, the replacement session must first read the live Issue/branch/PR state. Never assume a failed client response means the GitHub mutation failed.

## Mission priority order

Every `حرك مشروع FOOD` pulse uses this selection order:

1. repository-local RED CI / merge conflict on a Mission child;
2. merge-ready/green lane that can be completed;
3. stale/handoff-ready existing lane, same branch/PR;
4. dependency-critical READY lane;
5. other READY disjoint lane while active count < 6;
6. WAITING_CI lanes are observed but not duplicated;
7. HUMAN_GATE only when no repository-local action can solve the blocker.

A Mission worker does not stop after one child. It refreshes #1001 and continues draining while a safe lane exists.

## GitHub usage controls

To avoid unnecessary GitHub/API/Actions usage:

- reuse the existing Worker Watchdog instead of adding another repository-wide poller;
- existing Watchdog recovery is event-driven plus scheduled recovery;
- do not rerun green checks;
- do not rerun the same failed workflow repeatedly on an unchanged SHA unless the failure is classified transient;
- prefer affected-area CI;
- use one branch and one PR per child for its entire lifetime;
- no coordinator comment spam; update only when mission state materially changes;
- cap implementation parallelism at six lanes.

## Product completion requirements

The Mission is not complete until the integrated result proves:

- Customer/Driver/Van compact mobile density and full-width use;
- clear Customer/Driver/Van app identity at login;
- Remember Me + secure biometric unlock parity;
- automatic/live data refresh with stale/offline truthfulness;
- Driver foreground new-order alert + exact-order deep link + dedupe;
- Dashboard unified Driver + Van Live Tracking;
- real Customer invoice presentation using authoritative values/settings;
- Arabic/English localization and localized business-data parity;
- synchronized Dashboard + Customer + Driver + Van release artifacts;
- cross-app AR/EN + RTL/LTR non-regression;
- applicable required CI green on the integrated target.

## Terminal real release build / owner Setup source

The final phase is a dedicated child Issue and dedicated last branch:

- Issue: **#1021**
- Canonical branch: `release/1021-uiux-v42-final-real-build`
- Dependency: **#1012 PASS**
- Purpose: produce the exact real installable release the owner will use for server setup.

#1012 freezes the final implementation SHA. #1021 must branch from that exact converged state and may not start earlier.

At execution time #1021 must:

1. read live `VERSION` and `docs/release/RELEASE_REGISTRY.json`;
2. promote to the next unpublished semantic patch version only after code freeze — expected **1.0.59** if 1.0.58 remains latest at that moment;
3. never reuse/move an immutable published release tag;
4. build `Release/FOODEX-Laravel-Setup.zip` from the exact final release source;
5. build synchronized versioned Customer + Driver + Van APKs;
6. build/refresh Dashboard Update Center artifacts;
7. write `BUILD_INFO.json` and `LATEST_RELEASE.json` with exact version/source/checksum provenance;
8. validate a completely clean fresh installation **from the Setup ZIP only**;
9. verify first Super Admin, installer lock/version, required permissions, and the final Van/commercial/Flash/UIUX mission behavior available after first login;
10. verify no `.env`, private key, signing file or other secret is packaged;
11. publish the immutable GitHub Release/tag and download/hash-verify its assets;
12. refresh the generated distribution branch with exactly the same final Setup/APK/manifest bytes;
13. publish a generated-artifact-only commit back to `release/1021-uiux-v42-final-real-build` so that this final branch itself contains the owner-downloadable final `Release/FOODEX-Laravel-Setup.zip` and synchronized generated Release artifacts;
14. verify the Setup ZIP SHA-256 is identical on the final branch, GitHub Release and generated distribution branch;
15. keep all repository-controlled build/setup failures on this same branch/PR until green.

If the initial VERSION-promotion workflow fails after the version has already been bumped, repair on the same branch and use supported manual workflow dispatch for that same version rather than incrementing another version just to retrigger automation.

A GREEN code/CI matrix without #1021's exact-head real Setup build and clean-install proof is **not Mission completion**.

## Completion

#1012 may declare only code convergence. **Only #1021** may declare the Mission COMPLETE, post the terminal release evidence matrix to #1001, and close #1001 after the real release build above is published and verified.

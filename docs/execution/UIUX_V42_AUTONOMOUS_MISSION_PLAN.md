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
| #1012 | `test/1012-v42-final-convergence` | Final convergence + umbrella closure | After #1011 |

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
- #1012 starts only after #1011 passes.
- #1012 must then converge the accepted implementation into `release/1.0.58-van-complete` and require the canonical Fresh Setup workflow to build and clean-install-test the Setup ZIP from that exact final implementation source SHA.
- #1001 closes only from #1012 after the complete mission matrix is green **and** the terminal real release build requirement below passes.

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

The last phase of the Mission is not another feature branch. It is a **real release build on the canonical release branch**:

`release/1.0.58-van-complete`

After all product/code convergence is accepted:

1. record the exact final implementation source SHA on `release/1.0.58-van-complete`;
2. run/observe `FOODEX Van 1.0.58 Fresh Setup` for that exact source SHA;
3. require it to build `Release/FOODEX-Laravel-Setup.zip`;
4. require clean-install validation using the ZIP only, including migrations, seed, first owner, permissions, Van/Flash/field operations and fresh-install acceptance;
5. require `Release/BUILD_INFO.json.source_commit` and `Release/FRESH_INSTALL_EVIDENCE.json.source_commit` to equal that exact implementation source SHA;
6. require the Setup ZIP bytes/SHA-256 recorded in BUILD_INFO to match the published ZIP;
7. require the workflow to publish the generated Setup/evidence/update artifacts back to `release/1.0.58-van-complete`;
8. allow only a generated-artifact publication commit after the implementation source SHA; that publication commit must not add business code;
9. verify no code delivered by Setup is newer than the recorded Setup source and no stale/older Setup ZIP remains;
10. declare the canonical release branch to be the owner-facing source for the final installable Setup.

A GREEN product CI matrix without this exact-head Fresh Setup publication is **not Mission completion**.

## Completion

Only #1012 may declare the Mission converged. It must post the final evidence matrix to #1001 and close #1001 only when every required child is genuinely complete **and the terminal real release build above is published and verified**.

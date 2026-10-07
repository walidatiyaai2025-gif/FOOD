# FOODEX UI/UX v4.2 Requirement-Complete Recovery Plan

Mission: **UIUX-V42-RECOVERY**  
Umbrella: **#1034**  
Integration target: `release/1034-uiux-v42-recovery`  
Final real-release gate: existing **#1021**  
Maximum active implementation lanes: **6**

## Why this mission exists

The previous UIUX-V42 execution could reach a green/closed state while real user-facing requirements were still missing. The failure mode was structural:

- the contract/PDF was split into implementation lanes without proving that every requirement had an owner;
- diff-based/static guards intentionally ignored some legacy screens;
- backend/persistence tests were treated as if they proved visual/interaction parity;
- final convergence trusted child Issue closure and green CI instead of independently checking the actual integrated product;
- release engineering proved package integrity but did not prove every UI/UX route matched the contract.

This recovery mission changes the Definition of Done from **Issue closed + CI green** to:

**Requirement ID -> owner Issue -> source/test evidence -> real runtime evidence -> independent final convergence -> exact-head release.**

## Authoritative sources

Workers MUST read:

- `AGENTS.md`
- `docs/design-reference/DASHBOARD_UI_UX_CONTRACT.md`
- `docs/execution/UIUX_V42_RECOVERY_REQUIREMENT_MATRIX.md`
- `docs/execution/UIUX_V42_RECOVERY_REQUIREMENTS.json`
- `docs/quality/LOCALIZATION_CONTRACT.md`
- `docs/release/RELEASE_ARTIFACT_CONTRACT.md`

If an older mission document conflicts with this recovery plan for UIUX-V42 completion, this recovery plan is authoritative for the recovery scope.

## Hard completion rules

1. Every requirement row has one owning Issue.
2. No row may end as `UNKNOWN`, `UNOWNED`, `PARTIAL` or `FAIL`.
3. A child Issue may not close without updating its owned matrix rows with evidence.
4. A static test/guard cannot substitute for runtime visual/interaction evidence where the requirement is visual/behavioral.
5. Legacy screens are in scope. "The diff guard did not touch it" is never an exemption.
6. AR/EN evidence is mandatory for user-facing requirements; responsive evidence is mandatory where width/layout changes behavior.
7. Final convergence (#1043) independently re-checks the matrix/source/evidence. Child Issue state is not proof.
8. #1021 cannot publish/close again until #1043 records the exact frozen recovered source SHA.
9. A package built from an older SHA is invalid even if its build succeeded.

## First wave — six parallel implementation lanes

| Issue | Canonical branch | Ownership |
|---|---|---|
| #1035 | `feat/1035-dashboard-admin-hub-compliance` | Dashboard/Admin Hub and admin-shell compliance outside sibling scopes |
| #1036 | `feat/1036-commercial-business-ux-completion` | Sales Control + Flash Offers structured business UX |
| #1037 | `feat/1037-fieldops-map-lookup-compliance` | Field Operations lookups + map-first geography + row actions |
| #1038 | `feat/1038-customer-v42-full-sweep` | Customer full mobile/runtime sweep |
| #1039 | `feat/1039-driver-v42-full-sweep` | Driver full sweep + exact-record notifications |
| #1040 | `feat/1040-van-v42-full-sweep` | Van full mobile/runtime sweep |

All six are READY from the recovery integration target. Workers must respect the file fences in each Issue. If a lane needs a shared file owned by another lane, wait for the owner lane to merge rather than editing it concurrently.

## Gates

| Issue | Branch | Start rule | Purpose |
|---|---|---|---|
| #1041 | `test/1041-uiux-requirement-coverage-audit` | after #1035-#1040 | independent requirement/static/raw-input/localization audit |
| #1042 | `test/1042-uiux-runtime-visual-evidence` | after #1041 | real runtime AR/EN + responsive visual/interaction evidence |
| #1043 | `test/1043-uiux-recovery-final-convergence` | after #1042 | independent 100% matrix convergence + freeze exact SHA |
| #1021 | existing canonical final branch | after #1043 PASS | build/publish/clean-install exact recovered source |

## Scheduling / Mission Drain

When the owner says `حرك مشروع FOOD`, `اشتغل على مشروع FOOD`, `FOOD MISSION` or `FOOD AUTO-HANDOFF`:

1. locate open umbrella #1034 from live GitHub;
2. inspect all child Issues, branches, PRs and exact-head CI;
3. first fix repository-local RED/conflict on an existing lane;
4. then finish merge-ready lanes;
5. then take over stale/handoff-ready lanes on the SAME branch/PR;
6. then start a READY disjoint lane while active implementation lanes < 6;
7. do not duplicate a running CI job on the same SHA;
8. after a lane completes, return to #1034 and keep draining;
9. only stop when the mission is COMPLETE or every remaining lane is genuinely human-gated.

## Evidence contract

Each requirement row records:

- Requirement ID;
- scope;
- owner Issue;
- source evidence;
- automated evidence;
- runtime evidence;
- locale/responsive evidence where required;
- final status.

Runtime evidence must reference the exact integrated lineage/head used by the gate. A screenshot from a different/stale build cannot satisfy the row.

## Defect handling

If #1041 or #1042 finds a defect:

- reopen/continue the owning Issue and canonical branch when practical;
- do not create a "retry", "v2", "final-final" or duplicate implementation branch;
- re-run the dependent gate after the fix is integrated;
- do not waive a requirement because the defect was found late.

## Completion

#1034 may close only after:

- #1035-#1040 implementation scopes are complete;
- #1041 reports 100% owned/static coverage;
- #1042 reports all required runtime/visual interactions PASS;
- #1043 records a frozen recovery implementation SHA with every matrix row PASS;
- #1021 builds, clean-installs and publishes the next release from exactly that SHA and all release artifacts identify the same source/version;
- no known repository-local UIUX-V42 requirement defect remains.

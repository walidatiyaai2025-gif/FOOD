# FOODEX 1.0.77 Production Program

Canonical tracker: #1380

## Objective

Ship a reversible FOODEX production trial with the real launch catalog, faithful production-quality catalog imagery, explicit provenance for internally completed data, a verified pre-launch snapshot, and an owner-controlled Production/Legacy switch.

The final production target is **1.0.77**. FOODEX 1.0.76 is historical/superseded for this production-launch program.

## Customer app hard freeze

The Customer application must not change.

- Frozen baseline commit: `4de5a24b2ca6bd9b448a79ca05f7f44c7d428f4d`
- Immutable path: `apps/customer_app/**`
- No source/test/asset/pubspec/dependency/localization/formatting/generated-file/runtime changes.
- Release tooling must adapt outside the Customer tree.
- Final certification must prove a zero diff against the frozen baseline.

## Integration branch

`release/1.0.77-production-program`

Workers branch from the latest integration head and merge back only after their own focused acceptance is green.

## Child lanes

| Worker | Issue | Lane |
|---|---:|---|
| 1 | #1381 | Customer freeze enforcement and frozen-lineage release tooling |
| 2 | #1382 | faithful production-quality launch images |
| 3 | #1383 | missing catalog values + per-field provenance |
| 4 | #1384 | verified pre-launch snapshot + exact restore engine |
| 5 | #1385 | Dashboard Production/Legacy switch |
| 6 | #1386 | Production Launch Gate + owner launch report |
| 7 | #1387 | 1.0.77 release line + 1.0.76 supersession |
| 8 | #1388 | independent integration certification |

## Parallel execution

Workers 1-6 may run in parallel when they remain in their exclusive lanes.

Worker 7 should compose release identity only after the release/freeze contracts from Worker 1 are known.

Worker 8 runs after implementation integration and must not hide or patch missing behavior.

## Production data contract

The pre-launch production dataset must remain recoverable exactly.

Before destructive launch mutation:
1. create a complete database snapshot;
2. verify snapshot integrity/restorability;
3. bind snapshot to release/source/database identity;
4. audit it;
5. block launch if verification fails.

The owner-facing switch must support:
- Legacy / pre-launch data
- FOODEX Launch / production data
- Revert to Legacy

A revert must restore exact pre-launch business data, not merely reseed an empty baseline.

## Catalog assumptions

The owner permits the team to fill missing launch-catalog values.

Every non-official value must:
- be deterministic;
- be tagged with provenance;
- carry a rationale;
- appear in the launch report.

No inferred value may be presented as an official source value.

## Image fidelity

Image work is reconstruction/restoration/upscale, not redesign.

Preserve:
- product identity;
- package geometry;
- labels/visible text;
- brand marks;
- colors;
- visible content/composition.

Minimum production threshold is 800px where required by current gate; target >=1200px when practical.

## Launch gate

The gate remains fail-closed and must include existing checks plus:
- frozen Customer lineage;
- catalog provenance;
- reconstructed image quality;
- verified pre-launch snapshot;
- restore verification;
- dataset switch readiness.

## Release hold

While `docs/release/FOODEX_1_0_77_PRODUCTION_HOLD.md` exists, 1.0.77 is a coordination state, not a publishable final release.

The hold must only be removed when #1380 is complete enough for final release composition.

## Final definition of done

- Customer zero-diff proof passes.
- Catalog is deterministic and production-ready.
- Inferred values are fully disclosed.
- All required images pass.
- Snapshot/restore is verified.
- Production -> Legacy revert is proven against content fingerprints.
- Re-launch after revert is proven.
- Launch report is complete.
- Exact 1.0.77 source identity is green.
- Final Dashboard Update is produced from the integrated 1.0.77 line only.

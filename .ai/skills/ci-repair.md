# Skill: CI Repair

## Rule zero
CI failure belongs to the same Issue/branch/PR. Do not create a retry branch.

## Diagnose exact head
1. Confirm current branch head SHA.
2. Read the exact failing job/log.
3. Check whether a newer push superseded the run.
4. Classify failure: product/code defect, test defect, deterministic policy violation, localization defect, localization/parser defect, environment/flaky infrastructure, or stale/generated artifact mismatch.

Do not rerun an unchanged deterministic failure.

## Repair principles
- Fix product code when product behavior is wrong.
- Fix a CI parser/gate when the gate is wrong, and add regression coverage for that gate.
- Do not weaken security/localization/repository policy to get green.
- Do not silence recurring false positives with broad allow rules.
- Keep generated artifacts/source lineage synchronized where required.

## Completion
Report failing job, root cause, fix commit, local/preflight commands, exact-head CI result and remaining external blocker.

Green means the tested contract passed; do not overclaim unrelated product acceptance.

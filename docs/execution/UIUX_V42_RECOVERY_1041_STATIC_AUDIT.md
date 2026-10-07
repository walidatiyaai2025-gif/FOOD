# UIUX-V42-RECOVERY #1041 — Independent Static Audit

Gate Issue: **#1041**  
Integration target: `release/1034-uiux-v42-recovery`  
Integrated owner baseline audited before gate-only fixes: `878f0ebdcf538c96b0296a539680bcc922b4ecb1`

## Scope

This gate audits the integrated source from all six owner lanes (#1035–#1040). It does not infer acceptance from child-Issue state. The deterministic gate is `scripts/uiux-v42-recovery-audit.py`; Required CI runs it against the **whole repository tree**, not merely the #1041 diff, and publishes `uiux-v42-recovery-static-audit.json`.

The gate verifies:

- one matrix/JSON row per Requirement ID with matching owner and non-empty evidence;
- no `UNKNOWN` or `UNOWNED` requirement state;
- owner evidence artifacts for Dashboard, Commercial, Field Operations, Customer, Driver and Van;
- legacy Dashboard route audits plus Customer/Driver/Van route/screen inventories;
- all admin Blade surfaces for routine numeric internal-ID entry and visible raw JSON controls;
- direct unlocalized status/state/channel/role/type/payment/unit rendering across admin Blade and all three mobile UI trees.
- whole-tree mobile layout guardrails for oversized headers and order/assignment no-wrap, plus required Customer/Driver/Van regression contracts for one-line filters, ellipsis actions, refresh/stale behavior and Van 19-screen responsive evidence;
- required Dashboard deterministic contracts for shared shells, compact row actions, navigation authorization and Customer/Driver/Van first-class administration.

## Independent finding fixed by #1041

The whole-tree audit found an integrated Field Operations defect that the prior diff-based guards could grandfather: Routing Policy creation still exposed `rules_json`, and routing simulation exposed `input_json` / `scope_json` as routine business controls.

#1041 fixes that defect in the integrated gate branch:

- normal Routing Policy creation now uses a structured multi-rule builder;
- routing mode is an authoritative enum selector;
- simulation uses repeatable key/value input and scope rows;
- server validation converts structured values into the existing canonical routing engine without changing routing persistence/evaluation semantics;
- complex JSON remains available only as an explicitly privileged Super Admin Advanced fallback;
- regression coverage rejects routine visible routing JSON from returning.

This is a narrowly scoped integrated fix because #1037 was already merged/closed before the independent gate exposed the defect.

The same whole-tree pass also found a legacy Customer B2B purchase-report defect: recent order numbers could wrap and the raw backend order status was rendered directly. #1041 now enforces a single-line/ellipsis order identifier and maps the status through the Customer order-status localization catalog, with an explicit localized unknown-status fallback.

## Status semantics

Q01–Q03 remain `OPEN` in source until exact-head #1041 Required CI executes the whole-tree audit successfully. Runtime visual/localization proof is not manufactured here; #1042 remains authoritative for V01/V02 and the runtime half of localization/RTL/responsive acceptance.

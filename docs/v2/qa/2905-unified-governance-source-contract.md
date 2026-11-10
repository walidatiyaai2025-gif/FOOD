# V2 #2905 / P385 — unified governance-as-contract gate

## Scope and source
Original Word discussion: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx.
Native paragraph P372 (stable matrix identifier P385), issue #2905, parent #2503.
Integration branch: Foodex_V2 only.

## Contract ownership
This issue defines a single machine-verifiable source/provenance and acceptance vocabulary for:
- UX: route-owned navigation, authorized entry, AR/EN, RTL/LTR, responsive/keyboard/a11y proof;
- Backend: exact Laravel routes, validation, data ownership, HTTP allow/403 deny, failure paths;
- RBAC: effective permissions from roles and overrides, tenant/store/channel isolation, DENY precedence;
- ERP governance: module entitlement plus independent operational readiness, maker/checker, audit, finance/credit and B2B Van-only / B2C Driver-only dispatch.

The contract is docs/v2/V2_UNIFIED_GOVERNANCE_CONTRACT.json. The verifier is
scripts/validate_v2_unified_contract.py. The FOODEX V2 Isolation Gate executes
it on pull requests with base Foodex_V2, along with adversarial unit tests.

## Fail-closed audit coverage
The four source matrices must share the original Word filename, parent #2503,
base Foodex_V2, native source pointers, canonical Issue URLs, unique row keys
and 422 unique Issue numbers covering #2504–#2925. P385 must resolve to
Issue #2905 and native paragraph P372. Negative unit tests deliberately remove
a row, repeat an Issue, change the base to main, remove native source pointers,
shift P385, remove RBAC, remove Van, or weaken HTTP denial/readiness.

## Navigation, role, permission and app classification
This is a governance/CI-only change: no new Dashboard page, route, API,
permission, role, module, data field, user write or deployment. Existing
authorization remains in Laravel; Sidebar displays only authorized links.
Customer: no binary, endpoint, status or navigation change.
Driver: no binary, endpoint, status or navigation change.
Van: no binary, endpoint, status or navigation change.
Therefore no new app implementation issue is warranted by this diff.
Actual future runtime changes must be assessed individually and linked to
dedicated V2 app issues when affected.

## Acceptance boundary (explicit)
The contract validator proves source-link consistency and evidence vocabulary,
not that every child Issue, UI route, API, mobile journey or ERP domain has passed
runtime acceptance. No staging/browser accessibility or MySQL production
certification is invented here. Each child Issue remains responsible for exact
allow/deny tests, responsive/AR-EN runtime evidence and approval. #2503 remains
OPEN until final exact-head audit and owner authorization. Do not equate a green
provenance CI gate with production-ready FOODEX V2.

## Reproduction
Run from repository root:
python3 scripts/validate_v2_unified_contract.py
python3 -m unittest discover -s scripts/tests -p test_v2_unified_contract.py -v

Record PR base, exact head SHA, required checks and isolated merge SHA in
Issue #2905. No main merge, release, tag or production deployment is authorized.

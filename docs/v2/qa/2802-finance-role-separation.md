# V2 P051 — Procurement, Accounting & Treasury role separation

Issue: [#2802](https://github.com/walidatiyaai2025-gif/FOOD/issues/2802) · Parent: #2503 · Source: FOODEX Unified RBAC/ERP/Sidebar discussion (2026-11-09), P051.

## Authority and placement

- **Canonical source**: `backend/config/job_roles.php` and the persisted `roles` / `permission_role` relations created by `CoreReferenceSeeder` and `BuiltInJobRoleCatalog`. No new Sidebar route, API, UI control or implicit permission is introduced.
- **Existing UI**: Administration → Security Center for role administration; the existing ERP Procurement, Finance and Treasury workbenches for authorized operations. Navigation visibility is never a grant.
- **Action boundary**: Procurement Buyer prepares procurement documents; Procurement Approver independently approves/ issues; Accountant may manage/post finance subject to transaction-level checker; AP Officer manages payables without GL-posting; Treasury Officer manages cash tasks without approval/post; Treasury Manager can approve within treasury-specific module and policy limits but is not a GL poster.
- **Scope**: source-built roles are global *assignments* under the current engine, but business-domain authorization and legal entity/store/channel/module/entitlement checks remain separate requirements. No right is inferred from a role name alone.
- **SoD**: `job_roles.risk_pairs.procurement_maker_checker` is advisory at role assignment. It does not authorize a maker to approve their own transaction. Existing `V2ProcurementTreasurySodBoundaryTest` and `V2FinanceTreasuryDutySeparationTest` exercise transaction-level maker/checker and are not replaced by this catalog regression.
- **Security**: role definitions are a baseline; effective permissions additionally respect active role, explicit DENY, module lifecycle and context. The new test asserts that a disabled module cannot revive its persisted grants and does not delete historic assignments.

## This scoped implementation

New database-backed Laravel test `V2ProcurementFinanceTreasuryRoleSeparationTest` asserts:

1. Six built-in job roles retain distinct permission bundles and required-module boundaries after seeding; persisted grants equal the canonical source definitions.
2. Critical cross-domain approvals, posting, issuance and security approvals cannot enter the wrong specialist bundle.
3. On clean, disabled modules, existing assignments survive for audit while effective permissions fail closed.
4. A procurement buyer, accountant and treasury officer cannot directly enter an unrelated ERP workspace in Arabic or English.

The test is deliberately **not** a migration that changes live users or role assignments. No existing workflow, permission or mobile API is modified.

## Per-app impact

| Surface | Classification | Evidence |
| --- | --- | --- |
| Backend | Additive PHPUnit regressions only | Tests target seeded RBAC and real Laravel HTTP, no change to application services or routes |
| Dashboard | No UI source change | Existing ERP and Security Center routes are exercised for negative access |
| Customer | No API or Flutter change | No commerce/customer auth/ordering endpoint changed |
| Driver | No API or Flutter change | B2C Driver-only dispatch contract unaffected |
| Van | No API or Flutter change | B2B Van-only dispatch contract unaffected |

No new mobile issue is required for this test-only change. AR/EN negative HTTP coverage is included; no rendered screen/layout change means UI visual evidence is not claimed.

## Shared finance permission caveat

The Treasury Officer and AP Officer both retain the legacy `finance.manage` permission in the canonical catalog. This is **not** evidence that their effective operational authority is interchangeable: Treasury and Finance require different module lifecycle, entitlement and transaction scopes. The tests deliberately verify the actual permission bundles **and** the disabled-module fail-closed result instead of falsely asserting that the same code cannot appear in multiple job definitions. Independent maker/checker and ledger limits remain enforced by their domain services.

## Acceptance still outstanding — do not close #2802

P051 asks for **formal adoption** of a complete procurement/accounting/treasury role model. This bounded regression proves a subset of today's source-controlled baseline, not that the full operational permission matrix, legal-entity scopes, approval ceilings, maker/checker policies, delegated overrides, production-exported identities, UI responsiveness and end-to-end actions have been approved. Approval of target business responsibilities and a non-destructive role-transition/data-remediation policy must precede any broader migration. Coordinate with active P111 Procurement, Accountant and Treasury issue owners. No direct production user mutation is authorized.

Record tested PR HEAD, exact-head Repository Policy, V2 Isolation and Required CI runs, and integration SHA only after GitHub actually reports them. Merge only to `Foodex_V2` after required green checks. Never promote to `main`, release, tag or production.

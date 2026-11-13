# V2 #2768 — T019R02 Procurement / Accounting / Treasury separation of duties

**Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, AUDIT T019R02. Parent #2503. Integration target **Foodex_V2 only**. No production mutation or account identifier may appear in public issue, code, or PR.

## Source-to-control mapping

The exported historical account snapshot highlights overlapping Wholesale Procurement, Inventory, Accounting, and Treasury assignments. This is **historical evidence**, not an authorization to change any current live user's roles. In particular, neither historical names nor the original role fingerprint are used to create an automated test fixture.

| Decision boundary | Server authority / verification | Regression |
| --- | --- | --- |
| Mixed historical job-role ancestry | `User::hasRole` / `User::hasPermission`, never infer Platform Owner or Super Admin | Synthetic four-role union lacks `roles.manage` / `users.delete` |
| Procurement + Finance + Treasury responsibilities | Finance module lifecycle + legal-entity entitlement + tenant resolver | Finance HTTP remains 403 before module/capability activation despite a Finance role |
| Finance posting decision / maker-checker | `FinanceApprovalService::requestApproval` and `approve` use canonical `WorkflowEngine` step with `allowRequesterDecision=false` | Same multi-role maker cannot approve own request; distinct Accounting checker can approve and auditable event is recorded |
| Finance Retail access | Finance Workbench scope is Wholesale, not foreign Retail store | Cross-channel Retail `store_id` request denies; no cross-store reconciliation |
| UI Sidebar projection | `AdminNavigation` derives server role/channel access; no independent grant | Multirole account lacks Retail administration and Security Center role-management access, both AR/EN |
| Role remediation | Governed security workflow, maker/checker approval and audit | No mutation of any historical or production account within this PR |

## Bounded delivery and nonduplication

This PR adds **only** `backend/tests/Feature/V2FoodexAccountCrossDomainSoDAuditTest.php` and this evidence note. It reuses tested production services and existing routes, with no new permission, route, Sidebar entry, database migration, business UI, or app API. Previously merged #2925 covers representative permission union, global DENY and inactive actors; this issue adds transaction-level Finance SoD and module/entitlement validation without editing that worker's files. Finance workstream PRs are untouched.

**Persona/impacts:** Wholesale procurement/accounting/treasury Dashboard security tested. **Customer:** no contract/API change. **Driver:** no B2C dispatch/collection contract change. **Van:** no B2B dispatch/settlement API change. No app source or data modifications. Existing B2B = Van only / B2C = Driver only constraints unchanged.

**UI:** Existing Finance Workbench / Sidebar authorization reused, so there is no new placement, AR/EN copy, RTL/LTR layout or accessibility element to implement. HTTP authorization evaluated in both locales. Manual staging acceptance remains under #2503.

## Explicit outstanding acceptance

This is a **synthetic technical regression**, not a completed remediation of an identified current account. Before closing #2768, an authorized least-privilege reviewer must privately reconcile historical-to-current account type, active status, effective global/store roles, direct allow/deny overrides, organization/company/legal-entity/store ownership, entitlement/readiness, procurement approvals, Treasury custody and Finance approvals. Define an approved role split, use a distinct authorized admin with audit and independent reviewer, demonstrate business process continuity and recovery, and retain sanitized nonidentifying evidence. Never assume blanket deletion or role stripping is safe.

Tests and CI to record at exact PR HEAD: `V2FoodexAccountCrossDomainSoDAuditTest`, Repository Policy, FOODEX V2 Isolation Gate, FOODEX Required CI Gate including Backend tests, syntax/Pint/PHPStan/security, AR/EN localization and MySQL/Redis fresh-install/upgrade/recovery. **Do not close this source issue solely because the bounded PR is green.**

# V2 #2695 — ACCOUNTANT operational B2B authority (T122R15)

**Source:** FOODEX Unified RBAC/ERP Sidebar 2026-11-09 / T122R15. Parent #2503. Integration exclusively `Foodex_V2`. Reuse original branch `test/2695-v2-accountant-finance-role-guard` and original PR #3191; historical commits retained via merge parent. Never merge/promote to main or production.

## Scope and placement
**Reuse → Extend:** Canonical built-in `ACCOUNTANT` (Finance specialist, tenant-global) and existing authorized Finance Workbench `admin.erp.finance.index`, not a new page/route/menu. `backend/config/admin.php` permits only the existing `ACCOUNTANT` global role to use the **Wholesale/B2B channel**. Existing `ACCOUNTING_MANAGER`, `TREASURY_MANAGER`, `SALES_MANAGER` and peer global roles are preserved byte-for-byte; Retail B2C global allowlist unchanged. No new permission assignment or direct tenant bypass: Finance lifecycle/readiness, effective store role, per-legal-entity entitlement and server-side operation permission remain authoritative.

## Actual HTTP, Finance workflow and negative authority proofs
- Original source tests retained: role catalog and AR/EN presentation, disabled Finance and inactive-role revocation, explicit `finance.post` user DENY, Retail unrelated-role non-escalation and direct Finance HTTP 403.
- New real B2B Finance Workbench HTTP: no fake FINANCE/SUPER_ADMIN role; `ACCOUNTANT` reaches existing Finance Accounts tab after Finance enablement **and** legal-entity `finance.foundation.read` entitlement. Missing entitlement still 403 even with Finance role. AR/RTL and EN/LTR `dir` verified against the response; B2C foreign-store request denied, read entitlement alone cannot create account without manage capability.
- New posted General Ledger workflow: `ACCOUNTANT` creates accounts, fiscal period and draft balanced journal using canonical GL services under Finance entitlements. Independent `ACCOUNTING_MANAGER` checker with approved finance limit is required; Accountant lacks `finance.approve` and cannot self-approve. Maker and checker are prohibited from posting; a *different Accountant* poster completes posting; ledger entries and audit event verified. No new maker/checker bypass or over-privileged grants.
- Routes, the UI structure, responsive behavior and pre-existing AR/EN language catalogs remain unmodified. Actual manual browser widths/accessibility and final UAT are deferred to #2503/#2750; code-level AR/EN, RTL/LTR, HTTP/SoD required checks must pass now.

## Per-application impact matrix
| Surface | Classification | Evidence |
| --- | --- | --- |
| Backend Laravel | **Affected** — narrow B2B role admission | `backend/config/admin.php`, real Finance HTTP and ledger tests |
| Dashboard | **Existing workbench authorized** | No duplicate screen; reuse Finance Workbench, canonical module/entitlement/permission and AR/EN rendering |
| Customer Flutter | **Not affected** | No customer API/auth/order/credit change or Flutter source change |
| Driver Flutter | **Not affected** | No B2C dispatch, driver wallet or proof API change; DRIVER ONLY maintained |
| Van Flutter | **Not affected** | No Wholesale van dispatch/route/visits/collection API change; VAN ONLY maintained |

## Verification
- Focused tests: `backend/tests/Feature/V2AccountantRoleAuthorityTest.php`.
- Require exact final PR HEAD: Repository Policy, FOODEX V2 Isolation Gate, FOODEX Required CI Gate, backend syntax/Pint/PHPStan/security, AR/EN, full Laravel suite and MySQL/Redis install-upgrade-recovery. Never interpret tests on older SHA as approval.
- Commit and release metadata must name this isolated branch and issue. MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL, not passed. Close code-complete leaf only after actual CI success and merge into Foodex_V2.

## Previous partial test slice
Earlier test-only PR #3191 remained DRAFT/CLOSED because `ACCOUNTANT` could not enter the B2B channel, and source SoD/role tests were missing. This follow-up completes the same issue within that same PR and preserves earlier tests and commit lineage.

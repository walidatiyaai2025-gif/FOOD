# V2 #2696 — Accounts Receivable Officer authorization (T122R16)

**Integration boundary:** `Foodex_V2` only. Parent #2503. No main, production, release, tag or live finance migration.

## Existing authoritative implementation

The built-in `AR_OFFICER` role is defined in `backend/config/job_roles.php`, seeded by `CoreReferenceSeeder` using `BuiltInJobRoleCatalog`, and localized in `backend/lang/{ar,en}/job_roles.php`. The current role is global with tenant business scope, requires the `finance` module, and grants `finance.view`, `finance.manage` and `reports.view`. It has **no** `finance.post`, `finance.approve`, `reports.export`, treasury/security, order dispatch or inventory grants. Never infer posting or approvals from a broad manage permission.

Placement is **Reuse**, not a new Sidebar route: the existing Finance → ERP Finance workbench (`admin.erp.finance.index`). Server authority is resolved by real role permission, Finance module lifecycle and legal-entity capability entitlement. Its receivables view is the existing `receivables` tab. The access matrix currently maps `finance.ar.read` to `finance.view` plus entitlement. No separate `AR_OFFICER` application screen is justified.

## Regression evidence added

`backend/tests/Feature/V2ArOfficerAuthorizationContractTest.php` verifies:
- Seeded immutable bilingual system role, tenant/global boundary and negative permission bundle.
- Disabled Finance module denies direct workbench access and effective Finance grants; temporary enablement restores grants but not post/approve/export; disabling retains auditable role assignments.
- A scoped per-user **deny** of `finance.manage` wins over the role while retaining read-only permission.
- A global AR Officer with an unrelated Retail Operations assignment cannot receive Finance authority at that Retail store or access its workbench.
- Deactivating the role revokes effective grants without deleting the user-role audit pivot.

**Important coverage boundary:** these focused tests do not certify the entire Accounts Receivable collection/settlement workflow, real ledger reconciliation, approvals, cross-legal-entity data reads, or all Enterprise Finance entitlements. `finance.manage` is an existing broad permission shared by multiple finance actions, so its business-specific mutation scope needs independent reconciliation with Finance/API owners before certifying full T122R16 acceptance. Do not claim that a green role test alone completes the source requirement.

## Concurrent-work exclusion

During preflight PR #3218 was OPEN on `Foodex_V2` and changed `backend/app/Enterprise/Finance/AccountsReceivableService.php` and `backend/app/Http/Controllers/Admin/FinanceWorkbenchController.php`. These files are read-only for #2696. This PR adds only issue-scoped regression tests and this source record. Related #2695 Accountant and #2697 AP Officer have separate issue ownership.

## Cross-app impact

- **Customer:** no customer app, API, identity, B2B credit, price or order contract changes in this test/document delta; no Flutter work required for this delta.
- **Driver:** no Driver auth, B2C dispatch, wallet or settlements contract changes; B2C Driver-only dispatch invariant unchanged.
- **Van:** no Van auth, B2B visits, consignment, collections or routes change; B2B Van-only dispatch invariant unchanged.

If subsequently implementing AR mutations/API contracts, separately re-evaluate all three applications and link affected V2 mobile issues.

## Verification and completion policy

Run focused Laravel tests, syntax and Pint/PHPStan, AR/EN localization, V2 isolation and repository policy, plus the exact-head Required CI and MySQL/Redis fresh install, upgrade and recovery checks. Merge **only** on green exact head into `Foodex_V2`. Keep #2696 **OPEN** unless all T122R16 source/financial/approval, role scope and cross-app acceptance criteria have actually been completed; manual device/browser UAT is deferred under #2503.

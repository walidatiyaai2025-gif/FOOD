# V2 #2611 / Source Sidebar P281 — Finance Journals

## Canonical authority and placement

- **Domain/entity:** Finance & Treasury / `enterprise_finance_journals`, `enterprise_finance_journal_lines`, `enterprise_finance_ledger_entries`.
- **Role/goal:** authorized Finance viewers inspect journals and drill into exact-record provenance; authorized Finance makers create a balanced draft; an independent Finance poster may post only through the canonical posting/approval and SoD rules.
- **Existing parent and user flow:** Management Dashboard → Finance → ERP Finance Workbench → **Journals** tab. Canonical `admin.erp.finance.index?tab=journals&store_id=<authorized-store>`, renderer `admin/finance-workbench.blade.php`, controller `FinanceWorkbenchController@index`. Journal row “View” opens the exact record in the same tab with `journal=<id>`. Return/back path is the Journal tab. This is a `PAGE_TAB` (reuse), not a new standalone page or duplicate Sidebar item.
- **Interaction and responsive placement:** existing compact journal grid, “Create” in a disclosure/form for permitted makers, exact-record source/audit/approval and ledger detail. Reuse shared Finance Workbench AR/EN RTL/LTR, responsive table/forms, semantic links/actions. There is no UI redesign in this scoped test-only PR.
- **Read authority:** active/certified finance module + `finance.view` + legal entity `finance.gl.read` and foundational read for account selection; `GeneralLedgerService::paginateJournals` scoped by organization/company/legal-entity.
- **Write authority:** `finance.manage` and `finance.gl.manage` entitlement for `POST admin.erp.finance.journals.store`; `GeneralLedgerService::createDraft` validates balanced lines, posting accounts, an open fiscal period, idempotency key and audit. Draft creation does **not** post ledger entries.
- **Post and SoD authority:** `finance.post` plus `finance.gl.post` entitlement, separate maker/poster and existing canonical `FinanceApprovalService` prerequisites for `POST admin.erp.finance.journals.post`. Manager or menu visibility alone grants no posting privilege. No authority or allowance is duplicated in the test.
- **Enforcement:** middleware and `FinanceWorkbenchController` establish authenticated authorized tenant context; `GeneralLedgerService` re-authorizes permissions, module/entitlements and scoped records. User/store explicit `DENY` takes precedence over multi-role grants. Direct record lookup is scoped; no foreign journal detail/approval/posting.
- **State and finance invariants:** draft/posted states, request identity, immutable audit/ledger posting, fiscal-period checks, no duplicate ledger/second accounting book, no destructive migrations or reset. Existing `EnterpriseGeneralLedgerFoundationTest` covers posting approval/SoD, idempotency, immutable ledger, open/closed periods and account constraints.

## New issue-focused regression

`backend/tests/Feature/V2FinanceJournalsRouteAuthorityTest.php` covers:
1. Canonical HTTP route registration and genuinely localized AR/EN tab in RTL/LTR, readable to a scoped finance viewer while form/create POST is denied.
2. Entitled manager's real HTTP draft creation: balances persist, two lines and `enterprise.finance.journal_created` audit persist; repeated exact idempotency request does not create a second row. Manager without independent `finance.post` cannot post, preserving zero immutable ledger rows.
3. Separate organizations/legal entities for owner and outsider. Own read works but foreign journal list/detail and approval are inaccessible, while forged foreign-store selection is 404. Original draft remains untouched.
4. Two assigned active store roles, with `finance.manage` inherited from the second; an explicit store-scoped `DENY` revokes mutation without removing the user's `finance.view` read.
5. Disabled Finance module rejects both tab read and direct create POST without writing a journal.

Synthetic accounts, future fixed fiscal dates and test-only stores are used; no real identities, financial data or live accounts are touched.

## Customer, Driver, Van impact assessment

- **Customer:** no change to sales checkout, customer ledger, invoice, pricing, session, API responses, notifications or Customer Flutter navigation; no mobile issue required.
- **Driver:** no change to B2C dispatch, driver collection, wallet, delivery state, notifications, API or Flutter runtime; no mobile issue required.
- **Van:** no change to B2B Van-only fulfillment, Van dispatch, inventory, finance collection, settlement, authentication, API or Flutter runtime; no mobile issue required.

Each application is explicitly classified as not affected because this PR adds only backend regression coverage and source-trace documentation to **existing** journal implementations, with no production API/UI/mobile modifications. The domain-specific posting/financial contract remains protected by existing canonical tests.

## Verification and acceptance

- Source: discussion P281 → issue #2611 → parent #2503, isolated `Foodex_V2`.
- One new issue-scoped test and one QA markdown file on one branch/one PR. Confirm latest **exact-head** Repository Policy, V2 Isolation, Laravel tests, Pint, PHPStan, Composer Security, OpenAPI, localization and MySQL/Redis fresh install/upgrade/recovery checks before any merge.
- Any test failure must be repaired in the same PR, not bypassed. Final CI URLs, tested SHA and actual integration SHA belong in the Issue closure comment.
- `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` per latest owner decision on #2503; manual browser/staging/device acceptance is not claimed.
- **Never** integrate into `main` or create release, tag or production deployment from V2.

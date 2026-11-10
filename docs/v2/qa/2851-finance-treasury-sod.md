# FOODEX V2 #2851 — P127 Finance GL versus Treasury / collection SoD

**Source:** FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, P127; parent #2503. All work targets **Foodex_V2 only**. No production action or promotion to main.

## Canonical authority and implementation placement

Reuse existing finance and treasury services rather than creating a second ledger, approval table, permission engine or UI route.

| Step | Canonical authority | Mandatory distinction and proof |
| --- | --- | --- |
| Finance journal draft | GeneralLedgerService::createDraft, permission finance.manage, finance legal-entity entitlement finance.gl.manage | Maker creates auditable draft only, no ledger posting |
| Finance checker | FinanceApprovalService::approve, finance.approve, active role-specific monetary limit and exact scope | Maker cannot self-approve; approval provenance stored |
| Finance poster | GeneralLedgerService::post, finance.post, finance.gl.post and approved checker workflow if approval limit applies | Poster differs from maker and checker; posting writes balanced ledger and audit atomically |
| Treasury receipt draft | TreasuryReceiptService::createDraft, finance.manage plus store-scoped treasury.receipts.manage entitlement | Collection is a distinct Treasury record, not a GL journal |
| Treasury receipt approval/post | TreasuryReceiptService::post, finance.manage plus treasury.receipts.approve entitlement | Approver differs from maker, target revalidated, audit and outbox on success |
| Treasury payment | TreasuryPaymentService and EnterpriseTreasuryPaymentFoundationTest | Independent maker/approver and idempotent payment lifecycle |

**Navigation placement:** reuse canonical Finance and Treasury workbenches; no new Sidebar, route or screen is justified by this cross-domain security task. Server route/controller and enterprise service remain the authority, not menu visibility. Positive HTTP coverage exists in FinanceWorkbenchTest and EnterpriseTreasuryWorkbenchTest. Direct authorization denial coverage exists in V2FinanceCommercialAccessMatrixTest. Active #2839 owns concurrent operational-readiness HTTP changes; do not overlap its routes or tests.

## Added isolated test matrix

New suite: backend/tests/Feature/V2FinanceTreasuryDutySeparationTest.php.

1. Arabic finance maker, English checker and independent poster: create a fiscal period, balanced journal and live approval limit. Reject premature posting, maker approval, maker posting and checker posting. Only a third actor posts; assert two GL entries and an audit event.
2. Finance permission/entitlement does not activate Treasury: direct Treasury create returns HTTP 403 and does not create a receipt.
3. English Treasury receipt maker, Arabic approver: real cashbox, draft and independent approval; reject self-approval and preserve draft; post only with a separate actor and audit event.
4. Treasury-only module access does not grant GL creation: finance disabled, direct service action returns HTTP 403 and creates no journal or GL entries.
5. Existing suites must also run: EnterpriseFinanceApprovalFoundationTest, EnterpriseTreasuryReceiptFoundationTest, EnterpriseTreasuryPaymentFoundationTest, EnterpriseTreasuryReconciliationFoundationTest, EnterpriseTreasuryWorkbenchTest, V2FinanceCommercialAccessMatrixTest.

## Remaining P127 authorization limit — requires acceptance

Treasury services currently reuse **finance.manage** for both draft and approval. Separate Treasury entitlements and transaction-local maker/approver IDs are checked, but a person granted both Finance and Treasury modules in the same scope may still have access to both kinds of duty. This PR tests module fences and transaction-local separation, **not** an organization-wide rule forbidding one employee from holding both duties.

Completing P127 as an incompatible-role policy needs explicit owner approval for the role conflict matrix, exceptions, historical assignments and migrations. Then implement independent server Treasury action permissions or effective cross-role restrictions, reconcile seeds and roles and extend HTTP 403/audit tests for simultaneous entitlements. **Issue #2851 remains OPEN** until that is delivered and acceptance evidence is verified, regardless of this QA PR merge.

## Customer / Driver / Van impact matrix

| Surface | Impact | Evidence |
| --- | --- | --- |
| Backend | Additive QA only; no live API, authorization, route, migration or runtime change | Two issue-owned test and doc paths |
| Dashboard | No view/sidebar change; AR/EN and RTL/LTR existing contract remains in force | Existing workbench/localization suites |
| Customer | Not affected: no account/credit/statement API change | No API or Flutter diff |
| Driver | Not affected: no collection, wallet, or B2C dispatch change | No API/Flutter/dispatch diff |
| Van | Not affected: no B2B, van collection, route or visit change | No API/Flutter/dispatch diff |

B2B fulfillment remains VAN only; B2C remains DRIVER only. No production release, deployment, cPanel update, artifact publication or main merge.

## Acceptance ledger

- [x] Canonical server domain controls and transaction persistence inspected.
- [x] Additive regression on Finance and Treasury transaction-local SoD and independent entitlements.
- [ ] Exact-head green Required CI and V2 integration commit — record after verification.
- [ ] Organization-wide incompatible roles and simultaneous entitlement rule approved and enforced.
- [ ] Browser / AR-EN responsive audit if later access UI changes.
- [ ] Final owner acceptance and issue closure.

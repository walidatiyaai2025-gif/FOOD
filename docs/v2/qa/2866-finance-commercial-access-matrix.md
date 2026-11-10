# V2 #2866 — P144 finance/commercial HTTP + database acceptance matrix

Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P144. Parent #2503. Permanent integration target: **Foodex_V2** only.

## Scope and authority

This is a **QA and acceptance** issue. It reuses existing authoritative services, real Laravel HTTP routes, and persisted tables; it does **not** introduce new Maker/Checker logic, new privileges, duplicated credit calculations, migrations, workflow screens, or producer contracts. Negative HTTP/data-security integration regression is added at `backend/tests/Feature/V2FinanceCommercialAccessMatrixTest.php`. Positive domain lifecycle proofs continue to be exercised in the canonical Feature suites below, all of which are part of the exact-head Required CI full backend test job.

| Domain / actor | Actual route or service | Authority / gate | Positive and negative proof |
| --- | --- | --- | --- |
| Enterprise Finance maker/checker/poster | `admin.erp.finance.journals.store`, `admin.erp.finance.journals.approval`, `admin.erp.finance.journals.post`; `FinanceApprovalService`, `GeneralLedgerService` | Active ERP Finance module, scoped organization, finance permission, approved checker and separate posting actor | `Enterprise/EnterpriseFinanceApprovalFoundationTest` verifies draft→request→different checker→different poster, limit, provenance, idempotency, denied self-approval, cross-scope/delegation and DB state. P144 matrix verifies unauthorized direct HTTP cannot create a journal or approve |
| Customer-service discounts | `admin.operations.orders.customer-service-discount`, `OrderCustomerServiceDiscountService` | Assigned support role/store/channel, pending order, valid reason, idempotency, no finalized payment | `OrderCustomerServiceDiscountTest` verifies allowed assigned agent/manager, duplicate key, revision, paid order denial and cross-store. P144 matrix asserts unrelated Retail inventory actor cannot mutate Wholesale discount or audit-worthy financial totals |
| Wholesale B2B credit | `/api/v1/b2b/account-summary`, `/api/v1/b2b/account-statement`; `CommercialCreditEligibilityPolicy::assertEligible` | Approved B2B buyer, actual ledger/credit limit and store | `Enterprise/CommercialCreditEligibilityPolicyTest` verifies accurate ledger/store accounting and over-limit 409; `B2bFinanceTest` verifies owned invoice/statement API. P144 matrix proves over-limit failure leaves orders intact, approved account reads while pending account returns 403, AR/EN |
| Supplier procurement and settlement | `admin.erp.procurement.suppliers.store`, `SupplierDirectory`, `EnterpriseProcurementController` | Procurement module enabled, entitlement and company/store/organization scoped supplier permissions | `EnterpriseSupplierFoundationTest` verifies audited create/update/archive, scoped isolation and negative cross-organization read; `EnterpriseProcurementWorkbenchTest` verifies active workbench and module-off gate. P144 matrix confirms unauthorized direct POST yields no supplier record in either locale |

## Dashboard placement, UI, accessibility and state contract

Existing ERP Finance workbench, ERP Procurement workbench and Operations→Orders actions are the correct entries. **Reuse existing routes/screens** rather than adding a Sidebar entry, screen or decorative control. Finance is available only through actual module/entitlement/role readiness. The negative tests do not bypass the admin middleware; they exercise actual server HTTP and assert 403/404 rather than a redirect or 200. Existing AR/EN UI test suites provide route/localization and RTL/LTR rendering for authorized workbenches and order dialogs. This QA-only change does not alter CSS, DOM, focus order, responsive layout, or accessibility labels; real browser/screen-reader proof remains a separate acceptance step where required by parent V2 governance.

## Specific negative coverage added

1. Authenticated but unauthorized Retail inventory staff cannot write Wholesale discounts, procurement suppliers or finance journals through the real admin HTTP endpoints; persistence counts and Wholesale order totals remain unchanged in both Arabic and English.
2. Approved Wholesale buyer's real finance API summary remains readable, but an inactive/pending approval state denies statement access. A credit policy rejection (409) does not create an order.
3. Non-Finance B2B operator cannot use disabled Finance/Procurement modules or an approval route, and no Finance/Supplier rows are created. Real module guards are used, not UI-hidden affordances.

No live financial, account, supplier, password, organization or production data is queried or changed; fixtures are ephemeral test-only data. No secret or account identity is published.

## Per-application impact assessment

| Surface | Assessment | Evidence |
| --- | --- | --- |
| Laravel backend | **QA affected** | One test suite exercising existing finance, supplier, credit and discount domain contracts; no production service changes |
| Dashboard | **QA affected only** | Existing Finance/Procurement/Operations HTTP routes are exercised; no new UI or navigation change |
| Customer Flutter | **No implementation or API-contract impact** | Existing read-only B2B finance API routes tested without endpoint/payload changes; Customer Dart untouched |
| Driver Flutter | **No impact** | No Driver delivery, pricing, settlement, authenticated endpoint or payload changes; Driver Dart untouched |
| Van Flutter | **No impact** | No Van-only dispatch, assignment, customer account or collection API/payload change; Van Dart untouched |

## Delivery and acceptance boundary

Run the focused P144 suite and all existing cited canonical domain suites in the exact-head Laravel CI, plus syntax/Pint/PHPStan/security, localization, V2 Isolation, Repository Policy and real MySQL/Redis Setup ZIP Fresh Install + Update ZIP Upgrade/Recovery acceptance. Save exact run URLs in Issue #2866. **Never claim full Finance/Procurement module operational readiness from module ENABLING/ENABLED alone**, and never claim browser or mobile runtime testing that was not executed. A green isolated CI and integrated test-only PR do not close the parent #2503 or establish complete production readiness.

No promotion, merge, tag, deployment or release to **main** or production. One Issue / one worker / one branch / one PR **base Foodex_V2**.

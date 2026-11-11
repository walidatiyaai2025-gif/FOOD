# FOODEX V2 #2618 — P288 Receivables aging / أعمار الديون

**Integration:** `Foodex_V2` only. Parent #2503. Source paragraph P288 of `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`. No `main`, deployment, production release or privileged user data.

## Canonical placement decision

- **Domain / entity:** Finance & Treasury → Accounts Receivable → posted AR documents and allocations.
- **Actor:** authenticated accountant/finance reader with effective `finance.view` within a selected authorized store/legal entity; other roles and unauthorized stores must fail closed.
- **Goal/workflow:** inspect due-date buckets, outstanding amount, allocation and document evidence as part of regular AR review.
- **Reuse → Extend → Compose → New screen:** **Reuse**, because the existing `admin.erp.finance.index?store_id=<authorized>&tab=receivables` page contains a real `Receivables` tab and an aging table, with source-document and journal navigation. A separate `/aging` route or another permission/visibility registry would create an inconsistent parallel authority.
- **Existing parent / navigation:** existing ERP Finance link (`erp_finance`) in the Finance business group; once permitted and certified, the Finance workbench's horizontal `receivables` tab is the intended discoverable child function. The Sidebar entry must not be treated as an access grant.
- **Pattern, density, and surfaces:** existing filter + paginated documents + bounded aging table and localized status buckets. Desktop table is scroll-contained; mobile web uses shared responsive Dashboard layout; AR=RTL and EN=LTR. No new component or mock screen is introduced.

## Real authority chain

`FinanceWorkbenchController::index` verifies module ENABLED, chooses only scoped/authorized store contexts, requires `finance.view`, resolves the enterprise legal-entity scope, evaluates `finance.ar.read` entitlement, then returns HTTP **403** for unavailable `receivables` tabs. A different Retail store ID must return **404**, rather than selecting a foreign enterprise scope. `AccountsReceivableService::aging` independently enforces the same view permission and `finance.ar.read` capability before querying **posted** AR documents by organization/company/legal entity. Only positive outstanding amount after posted allocations appears. It uses five buckets (`current`, `1_30`, `31_60`, `61_90`, `91_plus`) and caps results.

The test `backend/tests/Feature/V2ReceivablesAgingSidebarContractTest.php` exercises actual authenticated HTTP: unavailable module 403; real ready/entitled AR tab 200 in EN/LTR and AR/RTL; cross-Retail-store 404; finance foundation entitlement without AR capability 403; exact-store explicit `finance.view` denial 403 after previously authorized access. The test certifies only a **test-only version** and explicitly enables module/entitlements in its own fresh database. It does **not** certify production ERP readiness, create a security shortcut, mutate live finance data, or waive exact-head CI.

## Cross-surface impact

| Surface | Evidence-backed classification |
| --- | --- |
| Laravel backend | Test-only HTTP/authorization regression; existing AR data service, amount/allocations logic and scope untouched |
| Dashboard | Canonical localized Finance workbench reused; no new route/layout, and existing 44px controls/mobile responsive container preserved |
| Customer Flutter | No changed Customer API, invoices, B2B credit, UI route or authentication contract; no source modification |
| Driver Flutter | No driver wallet, collection, B2C delivery, API or auth contract modified |
| Van Flutter | No B2B Van dispatch, collection, route, territory, fulfillment or offline API contract modified |

B2B=VAN ONLY and B2C=DRIVER ONLY unchanged. No changes to maker/checker, finance posting, data schemas, API/OpenAPI, seeding, uploads, release or production.

## Acceptance status

- **Automated gate:** run Laravel/PHP/Pint/PHPStan, localization, Repository Policy, FOODEX V2 Isolation, and exact-head Required CI on this issue's PR. Do not claim pass before workflow evidence.
- **Remaining external gate:** independent real-browser responsive/a11y and staging persona acceptance can be performed at final V2 gate per #2503 owner decision. Actual certified ERP Finance/entitlement availability on isolated staging must be evidenced before declaring the operational page ready.
- **Scope limitation:** The existing aging list is bounded to 100 rows. This PR does not falsely promise a separate complete downloadable aging report or export; if P288 requires aggregate/full-ledger reporting beyond the existing canonical workbench, leave the Issue open for a separately scoped, approved continuation and performance test.

One issue branch, one PR, merge `Foodex_V2` only after green exact-head required checks. Do not close until all outstanding P288 acceptance is evidenced.

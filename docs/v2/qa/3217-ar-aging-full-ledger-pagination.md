# V2 #3217 — P288 full-ledger receivables aging

Parent #2618; integration target \`Foodex_V2\` only.

## Source gap and change
The existing Receivables tab called \`AccountsReceivableService::aging(..., 100)\` and displayed only those first 100 rows without pagination or aggregate totals. PR #3085 already delivered navigation/authorization checks, not an unlimited aging report. This follow-up implements the missing source completeness without a second route, new permission, or moving data across legal entities.

- Canonical route: \`admin.erp.finance.index?tab=receivables&store_id=<authorized-store>\`.
- Placement: Finance domain / Accounts Receivable document / Finance analyst / daily outstanding review / read-only tab / **Extend** existing workbench / responsive table plus two independent pagers. No Sidebar/nav change. Other placements are larger and unnecessary.
- Role: authorized \`finance.view\` reader with enabled Finance module and \`finance.ar.read\` capability at the exact enterprise legal entity. \`EnterpriseScopeAuthorizer\` enforces organization/company/legal entity, the selected Retail store within that entity, and explicit user DENY. This change adds no mutate/approve/post/export privileges.
- Data: only posted AR documents with positive amount outstanding after **posted** allocations; draft/cancelled allocations and documents excluded. Customer filter maintained. The companion AR document list is also restricted to the selected Retail store within a shared legal entity.
- Read path: one shared authorized query; separate \`aging_page\` paginator for row details (25 per page) and full unbounded SQL aggregation by aging bucket and ISO currency code. KWD/USD are never totaled together; no 100-row server-side cap on aggregate and no unbounded PHP collection.
- Amounts are database DECIMAL(18,3); no destructive migration, data rewrite, journal posting, maker/checker, retry or recovery path change.

## Acceptance evidence
\`V2ArAgingFullLedgerPaginationTest\` inserts 126 posted outstanding AR documents distributed across current/31-60-day aging buckets and KWD/USD, subtracts only posted allocations, excludes a cancelled document and a different Retail store sharing the same legal entity, verifies 6 pages and sums over **all 126**, AR/EN RTL/LTR rendering, AR capability deny, disabled Finance module, explicit user DENY and cross-store 404. Full source acceptance requires actual exact-head Required CI passing on this PR and its integration; test presence alone is not a pass claim.

## UI placement and accessibility
Reuse existing \`admin.finance-workbench\` / \`receivables\` tab, the existing \`finance-table-wrap\` table and \`finance-pagination\` styles. Summary table has semantic column headers; full rows have independent pagination and preserve current store/filter context. Existing AR/EN translation keys label bucket, currency, outstanding, and empty state; RTL/LTR inherited from the canonical layout. Shared mobile horizontal table wrap remains the existing responsive behavior; manually verified mobile runtime screenshots are **not** claimed.

## App impact matrix
- **Dashboard:** affected; read-only detail pagination and complete bucket/currency summary via existing protected HTML route.
- **Customer:** not affected; no Customer API payload, authentication, catalogue, order, or Flutter changes.
- **Driver:** not affected; no dispatch, collection, settlement API, or Flutter changes.
- **Van:** not affected; no B2B assignment, route, consignment API, or Flutter changes.

No production deployment, main merge, release version bump, or package publication. Parent #2618 remains open until complete P288 acceptance.

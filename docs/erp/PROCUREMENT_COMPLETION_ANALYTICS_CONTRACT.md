# Procurement Completion & Analytics Contract (#1970)

## Placement decision

Canonical parent: **ERP / Procurement**.

Canonical route: **`/admin/erp/procurement`**.

Decision: **Reuse → Extend → Compose**. This change does not add a top-level module, Sidebar group, second Procurement ledger, second report engine, or second chart system. RFQ/RFP, supplier governance, contracts, 3-way matching, dashboards and reports are composed into the existing Procurement workbench. Supplier quotation uses a scoped invitation portal because the external supplier is not an ERP admin actor; it does not create a second Procurement authority.

## Authoritative chain

The business chain remains:

`Purchase Request → approval → RFQ/quote when used → Purchase Order → Goods Receipt → Supplier Invoice → 3-way match → Finance handoff → Supplier Statement`.

Existing request/order/receipt/invoice/statement tables remain authoritative. RFQ and contract tables add procurement provenance and governance; they do not copy stock, Finance journals, supplier statements or purchase-order balances.

## Dashboard definitions

All dashboard values are store-scoped after server authorization.

- **Open PRs**: request status `draft|submitted|approved`.
- **Pending approvals**: request status `submitted`.
- **Open POs**: PO status `draft|issued`.
- **Overdue POs**: issued POs whose `needed_by` is before today.
- **Ordered not received**: issued PO quantity greater than posted GRN quantity.
- **Unmatched invoices**: invoice without an approved/auto-approved 3-way match.
- **Supplier aging/exposure**: handed-off supplier-invoice value using the current Procurement invoice/statement authority; no fabricated payment offset is introduced.
- **Active contracts**: `active` and inside the configured validity window.
- **Expiring contracts**: active contracts ending inside `PROCUREMENT_CONTRACT_EXPIRY_WARNING_DAYS`.
- **Active RFQs**: `draft|published`.

Charts use the same authoritative records:
- spend trend;
- spend by supplier;
- spend by canonical product category when `item_code → products.sku` resolves, otherwise **Uncategorized**;
- PO status;
- open-PO aging buckets;
- observed supplier lead time from PO `issued_at` to posted GRN `posted_at`;
- RFQ price variance/savings;
- contract expiry horizon.

No disabled/unauthorized surface is represented as a fake zero.

## Savings baseline

The savings baseline is snapshotted into the RFQ line from the approved/submitted Purchase Request's `estimated_unit_cost` at RFQ creation. Awarded value is the awarded supplier quote.

If the request has no estimated unit cost, the awarded quote price is used as the comparison baseline for that line, producing zero savings rather than inventing a historical baseline.

## Supplier governance

Supplier 360 is extended in place with:
- qualification: pending / qualified / conditional / unqualified;
- block/unblock state and reason;
- documents and audit history;
- order/receipt counts;
- observed average lead time;
- invoice count/spend;
- linked supplier contracts.

An unqualified supplier cannot be selected by the canonical PO creation path.

## RFQ/RFP lifecycle and supplier portal

RFQ state:
`draft → published → awarded` or `draft|published → cancelled`.

Publishing creates one invitation per authorized active supplier. The invitation secret is generated once, stored only as SHA-256, has an expiry, can be revoked, and is throttled at the public route. The raw invitation URL is shown to the ERP operator only in the publish response.

The supplier portal can submit/update its quote while the RFQ is published, including a private quote attachment. Attachments are stored on the private `local` disk. Admin downloads re-run ERP scope/RBAC authorization.

Award requires:
- published RFQ;
- submitted quote;
- approved source Purchase Request;
- compatible active contract when an RFQ contract is present.

Award hands off into the existing `PurchaseOrderManager`; it does not create a parallel PO implementation.

## Contract governance

A contract is scoped to organization/company/store/supplier and includes:
- validity dates;
- currency;
- optional value/quantity ceilings;
- committed value/quantity;
- private documents;
- renewal provenance;
- activate/terminate audit state.

A contract may be assigned only to a draft PO with the same supplier/currency and a valid active window. Issue fails closed if the PO would exceed a ceiling or a contract-backed line has no known unit price. Issuing increments commitments transactionally; cancelling an issued PO releases its commitments.

Expired presentation is derived from the end date even if a legacy row has not yet been state-normalized.

## 3-way match

The match compares:
- PO line quantity/price;
- posted GRN quantity;
- supplier-invoice quantity/price.

Normal partial invoices remain valid. Quantity variance represents over-invoicing beyond received quantity; the existing invoice manager already prevents a new invoice from consuming more remaining receipt quantity. Value variance compares supplier invoice price with PO price.

Tolerance is explicit and auditable:
- `PROCUREMENT_MATCH_QUANTITY_TOLERANCE`, default `0`;
- `PROCUREMENT_MATCH_VALUE_TOLERANCE`, default `0.010` transaction-currency units.

Outside tolerance → `pending` and Finance handoff is blocked. An authorized reviewer may approve/reject with note and audit. Approved or auto-approved matches may hand off.

## Exception truth

The dashboard exception panel uses:
- overdue issued PO;
- ordered/received variance;
- pending/rejected 3-way match;
- blocked supplier;
- expiring contract;
- failed Finance handoff from the authoritative `enterprise_outbox_messages` row linked by invoice `outbox_event_id`.

A stale draft invoice is not labeled as a failed handoff.

## Reports and Enterprise BI

Procurement owns six operational reports:
- open / overdue POs;
- spend analysis;
- supplier performance;
- RFQ savings / price variance;
- contract expiry / commitments;
- 3-way-match exceptions.

The same report data is bridged into the existing Enterprise BI Report Registry as:
- `procurement.open_orders`
- `procurement.spend`
- `procurement.supplier_performance`
- `procurement.savings`
- `procurement.contract_expiry`
- `procurement.match_exceptions`

This reuses Enterprise BI entitlement enforcement, queued/chunked large exports, and scheduled-report infrastructure. Procurement does not introduce a second reporting queue or scheduler.

## Responsive / locale / accessibility

The workbench reuses the shared #1968 Module Dashboard contract and the existing ERP shell.

Runtime visual evidence is owned by the existing dashboard capture entry for `/admin/erp/procurement`, which now lands on Overview. Required evidence is AR/RTL + EN/LTR at the repository matrix widths:
`320 / 360 / 390 / 412 / 430 / 768 / 1024 / 1280+`.

Tables retain bounded pagination; charts include text/table fallback; supplier quote and contract forms remain functional without client-side JavaScript; mutation buttons use server-side authorization and throttling.

## Migration / backfill safety

The migration is additive:
- no existing Procurement source-of-truth table is replaced;
- new columns are nullable/defaulted where needed;
- existing suppliers receive `qualification_status=pending`;
- existing POs remain valid with null RFQ/contract/quote provenance;
- existing invoices create match reviews on creation or bounded workbench access before handoff;
- down migration removes only #1970 additions.

The migration has a reversible contract test.

## Integration validation

After synchronization with current `main`, #1970 must pass the exact-head repository policy, backend lint/static/tests, localization, MySQL/Redis deployment acceptance, and Procurement dashboard runtime visual-evidence gates before merge.

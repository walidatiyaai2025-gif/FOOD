# V2 P193 — Sales quotations (عروض الأسعار)

Issue: #2523. Branch integration target: `Foodex_V2` only. Status: bounded implementation and UI/CI acceptance in progress; exact current head not yet certified.

## Placement, authority and discoverability

| Field | Decision |
| --- | --- |
| domain | Customer & Sales |
| entity | Sales quotation, before an order; not a checkout or stock reservation |
| role | Authorized Wholesale or Retail operations manager, constrained to effective store/channel |
| user goal | Draft, review, issue or cancel a priced commercial offer |
| workflow_type | Operational create → verify live price → issue or cancel |
| frequency / data density | Repeated business task, growing history (server pagination 20) |
| existing parent | Customers & Sales `accounts` sidebar group and existing `CommerceQuoteService` |
| placement | Compose canonical pricing in a scoped sales-quotations operational workbench; no new top-level group |
| screen pattern | Operational Workbench with exact-record details and a shared accessible creation modal |
| navigation_impact | One `sales_quotations` child under existing Customers & Sales group, shown only with effective `orders.view` permission; the HTTP controller independently requires an active authorized store and exact channel |
| desktop/tablet/mobile | Responsive workbench, shared modal on desktop and mobile, in-row desktop grid, stacked mobile cards |
| rtl_ltr | Language-keyed `lang/ar` and `lang/en`, direction-aware layout and focus |
| active_context | `sales_quotations` |
| back/fallback | Orders workbench and quote list; 404 for inaccessible store or quote |
| module/entitlement/readiness | Sales Quotations uses existing core CommerceQuoteService; no optional sales enterprise module or new entitlement is registered. Effective store/channel permissions must be present; disabled users/stores are denied. |

The source document specifies a durable Quotations destination. The existing public/mobile `/api/v1/quote` is ephemeral cart pricing, not an administrator's persisted pre-order commercial document. Therefore reuse of that endpoint *as a page* is insufficient. New admin-only route/workbench is justified, **composed on the existing canonical pricing engine**, without duplicating the price calculation or creating a second order workflow.

## Route/action/authority matrix

| Route | Action | Permission | Scope/channel |
| --- | --- | --- | --- |
| GET `/admin/sales/quotations` | list; select store | `orders.view` | Active store within `OperationalTenantScope::allowedStoreIds`, B2B or B2C |
| GET `/admin/sales/quotations/{quotation}` | show snapshot | `orders.view` | Record's store and exact channel; cross-store = 404 |
| POST `/admin/sales/quotations` | create draft | `orders.manage` and `orders.view` | Explicit authorized store; exact B2C customer store; B2B customer must have active account; product store/channel confirmed in pricing engine |
| POST `/admin/sales/quotations/{quotation}/issue` | issue after fresh price/stock validation | `orders.manage` and `orders.view` | Record's store/channel and draft status; row lock; expiry validation |
| POST `/admin/sales/quotations/{quotation}/cancel` | cancel draft | `orders.manage` and `orders.view` | Record's store/channel and draft status; row lock |

For real HTTP failures, server returns 403/404/422/409 or validation redirect as applicable, regardless of whether a user hides/displays a Sidebar item. Layout toggles never grant access. SUPER_ADMIN retains active-store and channel boundaries through the same scope service. This PR does not add or change role-grant matrices, which have independent V2 ownership.

A quote is recorded in `sales_quotations` with immutable monetary/line snapshot and audit events. No order, payment, dispatch, invoice, reservation or fulfillment is created. An issued quote is a record, not an agreement to honor an outdated price indefinitely. Prices cannot be accepted from browser inputs: `CommerceQuoteService` resolves tiers, quantity increments, commercial rules, promotions, stock, currency and tax. A stale draft cannot be issued; create a new offer with current prices.

## Customer / Driver / Van impact

- **Customer app:** no route, API schema, runtime, role or checkout behavior changed. The existing Customer `/api/v1/quote` remains its canonical independent contract. Shared service is *called* by the new Dashboard controller but not modified. Run `AuthoritativePricingQuoteTest` to verify parity.
- **Driver app:** no API, status, permission, assignment, payment or fulfillment change. A quotation is not an order and cannot be dispatched. B2C remains Driver-only.
- **Van app:** no API, direct-sale, visit, stock, settlement, or assignment change. B2B remains Van-only. A quotation is not a VAN dispatch.
- No mobile change issue is necessary unless CI/integration evidence discovers a contract impact.

## Acceptance matrix

- Authorized B2C store admin: true price ignoring submitted prices, create, list, show, issue, audit.
- Other Retail store: read/write/show/transition denied, including foreign customer/product.
- Disabled user/store: denied by server and navigation.
- Wholesale B2B: own channel, active-account requirement, Retail cannot read.
- State machine: only drafts can issue/cancel; stale pricing blocks issuance; no order created. An identical HTTP retry has one quotation ID.
- Localized AR/EN, RTL/LTR responsive and keyboard UX; shared FOODEX operational modal for create, prior submitted product rows retained after failed validation, empty/error states, pagination.
- Existing `AuthoritativePricingQuoteTest` for shared mobile pricing contract.
- Exact-head required CI, migration tests and real runtime/UI acceptance still need verification. Never treat draft PR as ready without green exact-head checks.


## Review checkpoint — 2026-11-12 (same issue branch, no release)

- Resumed existing PR #3246 after the verified 30-minute idle-lease release; reused the single branch and its original 12-path ownership fence.
- The formerly permanently visible create form is now composed in the repository's shared `data-foodex-operational-modal` control, with a compact page-toolbar action. Server validation failures repopulate all submitted item rows, and the shared dialog is reopened after DOM ready. A focused Laravel test covers the modal hook and old-input row retention.
- Removed an unnecessary page-level mobile layout override so the canonical shared mobile navigation/drawer rules remain authoritative.
- Original exact-head PR checks for `cac9f14af7cf7b7ad363dcb92c22b463aa13beab` were successful, but they do **not** validate the subsequent UI/test commits. Branch reconciliation used a two-parent merge based on `Foodex_V2@785637d3ec0af8ae5c65f16469699281439a7cad`; newer exact-head CI must pass before considering readiness.
- Manual/mobile browser accessibility and full source UI acceptance remain **unverified**; the PR must stay DRAFT until automated checks and applicable UI audit are reconciled. This does not authorize merge to `main`, staging, releases or production.

## Source correctness review — 2026-11-12 (stale lease takeover on existing #3246)

- The prior worker's head `0d9f3de3fc6a406ee367e324170b76d760d048da` had all exact-head Policy, Isolation, and Required CI green; the stale implementation lease was claimed on the SAME #2523 branch/PR after no new execution commit/CI for 30+ minutes.
- Independent contract review confirmed canonical `CommerceQuoteService` already enforces an active B2B account and blocks unavailable stock in `strict` mode. No second pricing policy or mobile API change was introduced.
- Added a *real HTTP regression* proving that a once-valid draft cannot be issued after inventory falls to zero with unchanged unit prices; reject with 409, leave the quotation in draft, write no issue audit and create no order.
- Hardened durable read history by storing an immutable `customer_name_snapshot` when an admin creates a quotation. The existing new `sales_quotations` create migration and controller both include the snapshot. Quotation details show this persisted name, without refetching a customer who might be renamed, archived or deleted. A new Laravel test verifies both rename and deletion keep the record readable with its original name.
- The snapshot is business-facing data, not a login secret or customer credentials. It is scoped to an existing authorized quotation and does not add any new public API field or cross-tenant exposure.
- Required quality proof: on the final new commit, verify Laravel full tests, Pint/PHPStan, localized AR/EN, V2 Isolation, real MySQL/Redis fresh-install and upgrade/recovery test, and the terminal `required-ci-gate`. Old-head success is not evidence for subsequent commits.
- **Manual** device/browser viewport interaction audit and final V2 staging acceptance are held for parent #2503, not represented as performed.

## Hosting / release

No production deployment, Release ZIP/APK, `main` changes or version tags are authorized by this issue.

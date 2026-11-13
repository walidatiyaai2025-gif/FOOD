# V2 #2572 — P242 Goods Receipts / الاستلام

**Source:** FOODEX Unified RBAC/ERP/Sidebar Discussion (2026-11-09), P242 (Procurement & Inventory). **Parent:** #2503. **Integration:** `Foodex_V2` only.

## Source-to-screen placement and entry contract

- **Domain/entity:** Procurement & Inventory → Goods Receipt, issued Purchase Order, receiving Warehouse.
- **Actors and user goal:** authorised procurement buyer sees pending/posted goods receipts, while an authorised procurement manager may create from an issued PO, cancel drafts and post with an independent server `procurement.goods_receipts.post` permission. No shared role name or Sidebar checkbox independently grants an operation.
- **Workflow/data density:** frequent operational fulfillment of approved POs; protected store-scoped list and record detail, with server-bounded pagination (25), status/PO filters, warehouse lookup from active stores, received quantities and audit trail.
- **Reuse → Extend → Compose → New:** **REUSE**, because the real existing `admin.erp.procurement.index` `?tab=receipts&store_id=<authorized>` is fully implemented. It has `admin.erp.procurement.receipts.store`, `receipts.post`, `receipts.cancel`, exact `receipt=<id>` detail, and a PO row `receive` link that opens the correct tab and PO context. No second `الاستلام` Sidebar link, route, duplicate controller, synthetic page or permission catalogue.
- **Navigation type:** `PAGE_TAB` within existing `erp_procurement` business-domain SIDEBAR item under Catalog/Procurement. **Canonical route:** `admin.erp.procurement.index` with `tab=receipts`; **permission:** `procurement.goods_receipts.view` on selected store. **Back path:** same Procurement tabs, PO list and available store selector. Source `EnterpriseProcurementController` / `GoodsReceiptManager` are HTTP and mutation authority.
- **Channel/store:** procurement specialists with active global Procurement roles and matching Wholesale company/store only; unrelated Retail store cannot be selected. `EnterpriseScopeAuthorizer` checks organization/company/tenant/store and permission, including explicit per-user DENY. Stock and warehouse receiving rules are always restricted to active warehouse in same store; no B2C/Van crossing.
- **Module gates:** lifecycle `procurement` ENABLED, authenticated ERP Dashboard, enterprise module entitlement, exact certified manifest readiness, and view/manage/post independently. Disabled or unhealthy module fails closed rather than yielding a dead usable link. The same module decision applies to existing Sidebar and protected ERP HTTP.
- **UI:** current FOODEX shared responsive workbench, translated tab `enterprise_procurement.tabs.receipts` (AR `الاستلام`, EN `Goods Receipts`), RTL/LTR, native form controls, keyboard-friendly tab/list/actions and empty states. Existing exact-record detail, PO reference and active warehouse lookup are reused. No new UI; manual 320px/desktop/keyboard/screenreader observation remains at final UX #2503 / #2750.

## Independent test acceptance (not duplicate P240/P241)

`backend/tests/Feature/V2GoodsReceiptsWorkbenchAuthorityTest.php` verifies:

1. Actual route registry, real selected-Wholesale-store HTTP 200 and AR/EN + RTL/LTR tab presentation for **PROCUREMENT_BUYER** and **PROCUREMENT_MANAGER**. Buyer: `goods_receipts.view`, cannot manage or post; Manager: view/manage/post all granted. The view's `can.receipt_manage` and `can.receipt_post` mirror actual store-scoped backend permissions.
2. Disabled/unready module denies HTTP and hides ERP Procurement entry, and Procurement Approver (without receipt-view), B2C Driver and roleless users are denied direct `tab=receipts` access.
3. Direct POST create/post/cancel by view-only Buyer returns 403 before record lookup even when route and form fields are syntactically valid; no goods receipt record is created.
4. A multi-role Buyer+Approver with an explicit user `procurement.goods_receipts.view` DENY cannot see Receipts tab or use direct 403, but may still legitimately see Suppliers. Wholesale Buyer cannot select Retail store.

Other existing canonical unit/feature tests cover Purchase Order issue and Goods Receipt quantity/active Warehouse restrictions, state transition, inventory receipt posting and audit (not reimplemented here). **No production controller, service, Blade, routes, schema or permissions changed.**

## User app impact

| Surface | Impact, authority |
| --- | --- |
| Backend | **Test only**, existing authenticated procurement HTTP and `GoodsReceiptManager` reused |
| Dashboard | Existing Purchase Orders → Receive link and Receipts page tab, no screen change |
| Customer | Not affected: no storefront, order, pricing, API, identity or Flutter change |
| Driver | Not affected: no B2C-only Driver dispatch, wallet, API or Flutter change; Driver cannot read Procurement |
| Van | Not affected: no B2B-only Van assignment, stock/consignment, trip, API or Flutter change |

## CI, source and manual boundaries

One issue/branch/PR, only **two new issue-owned test/QA files**; peer PR #3288 owns Purchase Orders test/QA and no overlap; no active worker's shared source is edited. Require **exact last HEAD** Repository Policy, FOODEX V2 Isolation, FOODEX Required CI terminal `required-ci-gate` and all applicable PHP/Pint/PHPStan/security/OpenAPI, Laravel full suite, AR/EN localization, real MySQL/Redis fresh-install/upgrade/recovery acceptance. Do not merge on queued/red checks. Merge **only** into `Foodex_V2`, then independent read-back and issue closure with evidence.

**MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL** under explicit owner #2503, UX #2750. No production, staging-account, live manual UAT, device screenshot, Setup/Update ZIP release, APK, tag or main merge is claimed.

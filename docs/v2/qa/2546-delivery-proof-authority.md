# V2 #2546 / P216 — إثبات التسليم / Proof of delivery

Source: FOODEX Unified RBAC/ERP/Sidebar Discussion 2026-11-09, paragraph P216 (Orders & Fulfillment), master #2503. Target integration **Foodex_V2 ONLY**. Manual browser/staging/device acceptance: **DEFERRED_TO_V2_FINAL** per owner.

## UI placement, navigation and true authority

- **Domain / entity:** Orders & Fulfillment / persisted order and its immutable B2C Driver DeliveryProof or B2B Van OrderVanExecutionEvent.
- **Actor and goal:** authorized operational viewer inspects recorded photo/proof of a specific order; this is a read-only, frequent exact-record workflow, not a public gallery.
- **Existing parent:** Operations → Orders, authorized Sidebar group key operations and item order_operations, route admin.operations.orders.index.
- **Placement:** **Reuse / minimal Extend**: existing Details drawer and driver/van proof links. Navigation type CONTEXT_ACTION, not a new Sidebar route, duplicate menu or permission. User opens the exact order from Orders grid with channel and order in query; close action goes back to the same scoped grid.
- **B2C proof:** admin.driver-live-tracking.proofs.show, authenticated GET /admin/driver-live-tracking/assignments/{assignment}/proofs/{proof}; DriverDeliveryEvidenceService verifies a valid assignment/proof/order match and authorization via effective orders.view OR independent drivers.tracking.view for the Live Tracking workspace.
- **B2B proof:** admin.operations.orders.van-proofs.show, authenticated GET /admin/operations/orders/van-assignments/{assignment}/proofs/{proof}; VanDeliveryEvidenceService requires effective orders.view on the exact B2B order/store and event/assignment/order identity.
- **Security:** scope is server-enforced; nonmatching proof IDs, other stores/channels and user-level DENY fail closed (403/404). A roleless or inactive account cannot gain access merely from Sidebar metadata. The B2B fulfillment actor remains VAN ONLY; the B2C actor remains DRIVER ONLY. There is no separate toggle, entitlement or ERP readiness gate for the existing core Orders read capability.
- **Data classification:** file paths are confidential internal storage metadata, not user-facing free input or URLs. Download stream sets private, no-store and nosniff headers.
- **AR/EN, RTL/LTR, UX and accessibility:** existing translatable detail.view_proof and detail.van_proof labels; existing responsive Dashboard drawer, anchors with keyboard/focus and secure rel=noopener; desktop, tablet, mobile web reuse same markup. No new UI primitive, master-data lookup, page, API or screenshot CI requirement.

## Real defect corrected

VanDeliveryEvidenceService previously set proof.available for any nonempty proof_path even when public Storage did not contain the file. The Orders controller also exposed raw Van execution-event rows containing nonempty paths and Blade rendered these as proof links unconditionally. Consequently users could see apparently usable buttons that served HTTP 404.

- VanDeliveryEvidenceService now verifies public Storage existence when generating proof links; the immutable execution record remains intact.
- OrderOperationsController adds an internal proof_available boolean to raw Van execution details based on public Storage existence; Orders Blade uses this server-resolved value rather than trusting a nonempty path.
- Existing streams, permissions, proof records, upload paths and API contracts are unchanged. No missing proof object is fabricated or exposed.

## HTTP regression / cross-app matrix

The focused Feature suite V2DeliveryProofSidebarHttpContractTest validates:
1. Authorized B2C Driver proof is visible and downloads securely, AR RTL and EN LTR.
2. Cross-store foreign actor is denied; a forged assignment/proof pair cannot read another record.
3. Missing Driver proof is absent from UI and returns 404 if requested directly.
4. B2B Van proof is discoverable and streams with private/no-store/nosniff; a missing Van proof is not advertised by either Van list and direct request returns 404.
5. Cross-channel Retail actor and wrong Van assignment/event pair fail closed.
6. Explicit orders.view AND drivers.tracking.view DENY, roleless and inactive actors cannot download through a forged URL; hidden Orders navigation cannot grant direct access.

**Dashboard** affected only in read-view Van proof availability and regression coverage.
**Customer Flutter** not affected: no contract, endpoint, routing or customer delivery state change.
**Driver Flutter** not affected: capture, ledger, upload, API and B2C-only dispatch unchanged.
**Van Flutter** not affected: execution API, proof upload, B2B-only assignment unchanged.

Actual app/API changes: **none**; no mobile issue is required for a Dashboard-only read-model correction. No production data migration.

## Release/closure rules

One claimed issue, one issue-scoped branch test/2546-v2-delivery-proof-authorized-workflow, one PR into Foodex_V2 only. Required exact-head gates: Repository Policy, V2 Isolation, Laravel Backend tests, AR/EN localization, Backend fast-fail/Pint/static analysis/security/OpenAPI, MySQL/Redis fresh install/upgrade/recovery, terminal required-ci-gate. Record head/CI/merge evidence before closing completed leaf; final master #2503 remains open. No main merge, tags, releases or production deployment.

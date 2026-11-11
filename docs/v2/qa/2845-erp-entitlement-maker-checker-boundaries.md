# V2 #2845 / P111 — ERP entitlement and independent checker HTTP boundary

## Scope and route authority

Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P111; parent #2503. Integration target `Foodex_V2` only. This change repairs one demonstrated Procurement ERP entry-point gap; it is **not** full P111 certification across every ERP module.

- **Business domain:** ERP / Procurement, purchase requests and approvals. **Workflow:** buyer creates/submits, independent procurement approver decides.
- **Placement:** reuse the existing Procurement workbench (`admin.erp.procurement.index`, Requests tab), plus its current POST actions. No new Sidebar, route, screen, module toggle, or decorative UI was introduced.
- **Server authorization:** `EnterpriseProcurementController::context()` is shared by the workbench and all Procurement HTTP mutations. When a Procurement entitlement policy has been configured (any Procurement entitlement row), it requires `ModuleEntitlementResolver::allows('procurement', store scope)` before returning the resolved context. This respects module lifecycle and the narrowest explicit entitlement, including store DENY beneath platform ALLOW. A legacy deployment with **zero Procurement entitlement rows** continues to use its existing module lifecycle, role, tenant/channel and SoD authorization; it is not silently declared entitlement-certified. The readiness middleware continues to require operational certification. Absence of a matching entitlement **after** module policy enrollment remains fail-closed.
- **Roles:** global `PROCUREMENT_BUYER` and `PROCUREMENT_APPROVER`, dual-role maker as an adverse case, and `SUPER_ADMIN` as control plane. Neither a global role nor Super Admin can override explicit store entitlement revocation for these routes.
- **Data and failure paths:** original submitted request persists unchanged on denial; no approval event is written; the independent checker can approve only after explicit store re-entitlement. The same maker is rejected even after receiving the checker role.

## Executable acceptance

`backend/tests/Feature/V2ErpEntitlementCheckerBoundaryTest.php` uses a seeded authenticated HTTP workflow and a real persisted request. It tests:

1. Lifecycle-enabled, platform-entitled, explicitly operationally certified Procurement with an existing buyer-submitted purchase request.
2. Store-level entitlement revocation after submit; Arabic and English HTTP GET and approval POST return 403 for checker, maker and Super Admin as applicable, and no approval transition/audit occurs.
3. Restoration of the same store entitlement and dual-role maker self-approval rejection with a localized validation error; the separate checker succeeds once with a persisted approval audit.
4. Scoped proof on the canonical Wholesale store; no Retail B2C role or cross-store authority is granted.

**Product parity:** Backend/Dashboard authorized Procurement HTTP behavior is impacted; Customer app public API and Flutter files are unchanged (no customer quote/order/credit or public endpoint change). Driver app unchanged (no B2C dispatch, assignments, collections or runtime endpoint change). Van app unchanged (no B2B visit, delivery, territory or dispatch API change). B2B = Van only and B2C = Driver only are preserved.

**First exact-head CI iteration:** Repository Policy, V2 Isolation, Localization, fast-fail and MySQL/Redis install/upgrade/recovery succeeded on `3e38fee8`; full Laravel tests reported 6 legacy procurement no-entitlement fixture failures (3063 passed). The subsequent compatibility guard fixes the uncontrolled pre-enrollment behavior without weakening explicit DENY; recheck all CI on the latest commit before claiming success. The test fixtures changed by active #2858 were deliberately not edited.

**Local validation:** GitHub Connector-only code edit environment does not include the checked-out repository with `vendor/`, DB and full worker bootstrap; `scripts/worker-preflight.sh --ci-parity`, Pint, PHPStan and Laravel/MySQL tests are not claimed locally. Exact branch-head GitHub Actions and its required child jobs must be verified before merge.

## Remaining P111 source work — do not close issue from this bounded fix

The P111 requirement also demands a complete ERP module/role/company/branch/legal-entity/store entitlement matrix and maker/checker SoD enforcement across Finance, Treasury, WMS, Procurement, and their related UI/HTTP entry points, with disabled-module, multi-role, cross-channel, audit and appropriate AR/EN/accessibility coverage. `#2858` / draft PR `#3065` separately owns operational readiness evidence; `#2695` and `#2699` own specific Finance/Treasury role security. Those are not certified or altered here. Do not merge conflicting changes to their files. Add source-exhaustive acceptance and complete remaining scoped work before closing #2845. Manual staging/browser/device UAT is deferred under the latest #2503 decision, but missing source implementation is **not** deferred.

**Prohibited:** No `main` changes, release, tag, production deployment, tenant-data alteration, or silent privilege grant.

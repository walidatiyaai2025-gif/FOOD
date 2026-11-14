# V2 #2840 — T104R03 ERP workflow readiness acceptance

**Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, paragraph/table `T104R03`. Master #2503. Target `Foodex_V2` only. No production promotion or account changes.

## Canonical contract reused

- `ModuleLifecycleManager` owns legal module transitions and records audit evidence.
- `ModuleEntitlementManager` owns scoped grant/revocation and audited capability authority.
- `ModuleOperationalReadiness::status` requires both exact manifest-version certification and a healthy live probe, in addition to an ENABLED lifecycle state. The default certification map is intentionally empty.
- `EnsureErpOperationalReadiness` guards canonical admin routes for procurement, WMS, finance, treasury and BI. Route controllers separately enforce identity, permissions, tenant/store/module/capability and financial SoD.
- `AdminNavigation` must project that same readiness, and existing `V2ErpFinanceProcurementReadinessGateTest` already covers the Sidebar side. This issue does not duplicate its tests or create a second policy.

## Bounded functional regression added in this PR

`V2ErpWorkflowReadinessCertificationTest` exercises **real authenticated HTTP** access to Procurement, Finance and Treasury module entry routes, not only Sidebar visibility.

| Scenario | Outcome |
| --- | --- |
| Active Super Admin, module ENABLED, PLATFORM entitlement enabled, no exact manifest certification | Direct module HTTP response 403 in AR and EN |
| Stale future/wrong manifest version certificate while module enabled and entitled | Direct module HTTP response 403 |
| Existing ERP landing page while feature modules uncertified | 200 and canonical document `dir=rtl` (AR) / `dir=ltr` (EN) |
| Exact manifest certified, module healthy | Canonical readiness reports `ready` |
| Certification explicitly withdrawn after readiness | Canonical readiness returns `uncertified`, direct route 403 while entitlement, module state, roles and audit records persist |
| No unrelated write during denied read | Audit event count unchanged |

## Impact and authorization matrix

- **Backend:** no new API, controller, entitlement, migrations, middleware or settings. Test-only assertions of existing direct-route guard; release certification is not set or modified in live config.
- **Dashboard:** no UI/navigation change; existing destination/readiness and AR/EN, RTL/LTR responsive behavior continues under existing shared contracts. User-facing integration still subject to final V2 acceptance.
- **Customer:** not affected: no public API, order/credit, customer auth or Flutter asset modified.
- **Driver:** not affected: no public API, assignment/delivery/collection or Flutter asset modified. Retail B2C remains Driver-only.
- **Van:** not affected: no public API, custody/trip/territory or Flutter asset modified. Wholesale B2B remains Van-only.
- **Authorization:** synthetic Super Admin still subject to operational readiness; no bypass of authentication, tenant, permission, module entitlement, maker/checker. Existing Role + Store + Finance tests remain authoritative for separate action-level checks.
- **Navigation placement:** existing ERP domain entry points only; no new route, tab or Sidebar item. Reuse before extend; no business-facing field added.
- **Localization:** no user-visible string added, and live AR/EN/RTL/LTR smoke evidence is in the new test.

## Source acceptance remains OPEN pending exhaustive evidence

**This narrow CI increment is not a declaration that all ERP business flows are certified.** Acceptance of T104R03 requires evidence links for real end-to-end creation/review/approval/posting/reversal/report/audit pathways in procurement, inventory, finance, treasury and BI; negative tenant/store/role/SoD tests across all supported profiles; healthy module probes; staging visualization and synchronized app compatibility. Required G0–G13 gates and manifest-version provenance must be signed off before a production certification version is added. Do **not** fill `enterprise_module_readiness.certified_versions` based on this test alone.

Run `php artisan test --filter=V2ErpWorkflowReadinessCertificationTest` from `backend/`. Exact-head Repository Policy, FOODEX V2 Isolation Gate and FOODEX Required CI Gate must all pass before any merge to Foodex_V2. If the new test exposes a readiness bypass, do not weaken the test or sidestep the guard. Fix the source in a separately coordinated file fence, with an issue-linked mobile impact check where relevant. Never target `main` or production.

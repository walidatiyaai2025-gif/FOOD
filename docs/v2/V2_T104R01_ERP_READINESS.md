# V2 T104R01 — Lifecycle ENABLED is not operational readiness

Issue: #2838. Integration: `Foodex_V2` only. Source: FOODEX Unified RBAC/ERP/Sidebar DOCX, T104R01.

## Authority and placement

Reuse the existing Administration Hub → Module Control Plane at `admin.enterprise-modules.index/show`; no second route or Sidebar node. Only `platform.manage` may inspect lifecycle/readiness. The ERP Center continues to project existing RBAC/tenant/entitlement decisions, but no longer displays an actionable module card if the module lacks operational approval.

`ModuleState::ENABLED` means **runtime lifecycle enabled**, not completed ERP delivery. `ModuleOperationalReadiness` requires all three independent conditions:

1. exact lifecycle state `ENABLED`;
2. a reviewed, exact manifest-version entry in `config/enterprise_module_readiness.php` (`certified_versions`), default none;
3. current health status `HEALTHY`.

A missing/wrong-version certification is `uncertified`; failed/degraded health is `unhealthy`; any other lifecycle state is `inactive`. Unknown is never silently promoted to ready. Certification values must be added only in a subsequent reviewed PR with G0–G13 acceptance evidence; this patch grants no certificates. Health is necessary but never sufficient. Registry/entitlement decisions remain independent and are not overwritten.

## Security and impact

This issue adds no grant, permission, API/mobile response field, database mutation, or production setting. Direct route authorization remains the existing server-side controller and permission contract; this PR does **not** claim that rendering a disabled ERP card is an authorization barrier. Subsequent scope work #2839/#2840 must reconcile direct-route/Sidebar/module readiness enforcement without bypassing tenant/store/channel or SoD. Existing service/migration/data side effects are unchanged.

- Dashboard: explicit AR/EN readiness diagnostics in existing Module Control Plane, RTL/LTR and compact responsive cards unchanged; ERP Center hides an unapproved module's launch link.
- Customer: not affected (no mobile API or app contract changed).
- Driver: not affected (no Driver route/API/fulfillment change).
- Van: not affected (no Van route/API/fulfillment change).

## Verification

`EnterpriseModuleOperationalReadinessTest` covers disabled, enabled but uncertified, stale-version certification, exact-version approval, and unhealthy runtime. `EnterpriseModuleAdministrationTest` covers existing authorized AR/EN route and separation of state/readiness; existing Finance denial covers server-side 403. `ErpCenterTest` covers no operational link without exact-version certification and preserves explicit certified fixtures for prior acceptance assertions. Required exact-head CI, PHP lint/Pint/static checks and integrated UI/runtime evidence remain mandatory before this issue can close.

No `main` merge or production deployment is authorized.

# V2 #2847 / P113 — operational ERP Sidebar readiness

Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P113. Parent #2503. Isolated target: `Foodex_V2` only.

## Scope and navigation placement

- **Actor:** authenticated active global operational role with the necessary per-workbench business permission; role-only ERP eligibility is not a grant to operate a module. B2C store-only roles do not receive the platform ERP group. Inactive actors are denied by existing canonical `AdminNavigation` and `AdminSidebarManager` guards.
- **Domain / route owner:** existing ERP group of `AdminNavigation`; `erp_center` at `admin.erp.index` remains an *informational* available/not-entitled/degraded diagnostic destination. This is the established canonical entry point, not a new screen.
- **Reusable pattern:** Extend the existing `routeItemEnterpriseModule` method, not a duplicate Sidebar registry or authorization flag. Existing workbench targets are `admin.erp.procurement.index`, `admin.erp.wms.index`, `admin.erp.finance.index`, `admin.erp.treasury.index`, and `admin.erp.bi`.
- **Positive operational entry guard:** existing global role and route permission + module lifecycle ENABLED + effective enterprise module entitlement + exact manifest-version certification + healthy module probe. No automatic certification or implicit entitlement.
- **Negative operational entry guard:** disabled/uninstalled, missing/wrong certification, unhealthy probe, explicit entitlement denial or insufficient permission => no operational link. A verified Platform Owner retains the *protected module diagnostics* URL `admin.enterprise-modules.show` on non-ready modules, not the operational URL.
- **Route authority:** Sidebar visibility is only a projection. Direct HTTP authentication, permission, tenant/store and module readiness enforcement remains server-side in route middleware/controllers; V2 #2839 owns the distinct direct ERP HTTP-readiness gate. This change cannot bypass that gate and does not modify its code.
- **Language and access:** all shown text and AR/EN RTL/LTR come from existing approved translation keys and shared responsive Sidebar; no new labels or visual controls introduced. Existing semantic links remain keyboard navigable; no new aria tokens required. Independent staging/browser a11y verification remains necessary.

## Verifiable tests

`backend/tests/Feature/V2ErpSidebarOperationalGateTest.php` covers:
1. enabled + entitled + uncertified => no live module link; informational center still visible (EN LTR);
2. exact certified/healthy + entitled + authorized => canonical module route visible (AR RTL);
3. stale manifest-version certification => fail closed;
4. explicit entitlement DENY => fail closed;
5. unhealthy module probe => fail closed;
6. finance-only actor and actor with no global role => no procurement link; no-role direct ERP Center HTTP denied.

These are isolated Laravel feature tests and do not replace independent end-to-end staging acceptance or the distinct #2839 direct-route suite. Acceptance must verify latest exact-head CI, RBAC/SoD and multi-role behavior, cross-tenant/store isolation, accessible keyboard/mobile RTL/LTR, and module health in isolated staging before closing #2847.

## Mobile impact matrix

| App | Contract impact | Evidence / follow-up |
| --- | --- | --- |
| Customer | **Not affected** | No `/api/v1` route, token, customer permission, payload, navigation or Flutter file changed; this alters only Dashboard-projected ERP module links |
| Driver | **Not affected** | No dispatch/order/driver API or Flutter file changed; B2C DRIVER ONLY preserved |
| Van | **Not affected** | No B2B order/Van API or Flutter file changed; B2B VAN ONLY preserved |

No app-specific V2 mobile Issue is required for this server-only Dashboard navigation condition. Reclassify and create individually linked issues before merge if an API contract actually changes.

## Recovery and release

No schema, seed, environment, job, cache, credentials or release artifact changes. This change is reversible by reverting the isolated PR if required; it does not undo any database migration. No tag, main merge or production deployment is authorized. Final issue closure requires independent staging acceptance in addition to all required green CI.

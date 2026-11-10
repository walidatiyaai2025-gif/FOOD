# V2 #2884 / P360 — Sidebar operational-readiness projection

- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` paragraph P360; umbrella #2503.
- Integration branch: `Foodex_V2` **only**. Issue branch: `fix/2884-v2-sidebar-operational-readiness-projection`.
- Placement: **Reuse/Extend** the existing authorized ERP Sidebar business-group projection (`AdminNavigation::groupsFor`) and registered-owner module-details diagnostics. No additional screen, route, role, menu key, UI framework or independent visibility permission.
- Domain: wholesale ERP operational modules; entity: registered enterprise module; persona: Platform Owner (diagnose), authorized B2B operator / Super Admin (operate only when ready); goal: never advertise an unusable operation, provide owner a safe diagnosis path.
- Navigation: existing `SIDEBAR` group `erp`, existing `admin.erp.*` and `admin.enterprise-modules.show` routes, existing `admin.erp_center.modules.*.title` localization. The active page/route must be authorized by its server middleware/controller. Back/fallback: ERP Center / existing Settings module controls.
- Permissions: original exact `procurement.suppliers.view`, `inventory.view`, `finance.view`, `reports.view` checks, global/store tenancy, module entitlements, administrative owner identity and financial SoD unchanged. `AdminSidebarManager` still only hides/reorders already-authorized items.

## Exact readiness and denial boundary

1. For existing ERP Procurement, WMS, Finance, Treasury and BI item keys, navigation now asks canonical `ModuleOperationalReadiness::status(code, manifest.version, state)`. Only exact-version certified **and healthy** modules can project the existing operational route. Unregistered, disabled, suspended, uncertified, stale-version, unhealthy and lookup-exception states fail closed for all non-owner users.
2. A **registered** Platform Owner, authenticated through `platform_owner_registry` and its active system role, sees the existing module detail/diagnostics route (`admin.enterprise-modules.show`) instead of a misleading operational link for any unready state. No synthetic success or ERP action access results from that diagnostic link. A forged PLATFORM_OWNER role alone is not enough.
3. For a ready module, the original permission, channel, store scope, entitlement, and view route checks still apply. No extra permission is issued by a Sidebar node or preference. The owner can use the original operational link only under the existing authorizer.
4. **Separate upstream route-safety dependency:** #2839 / PR #2981 owns server-side direct ERP GET/write route readiness. This issue intentionally does **not** edit `backend/routes/web.php`, `EnsureErpOperationalReadiness`, controller workbenches or that worker's tests. Do not claim direct-URL readiness protection until #2839 is merged and its exact-head checks pass. Existing role/tenant checks remain independently enforced.

## Validation and evidence

- Scoped tests: `backend/tests/Feature/V2SidebarOperationalReadinessProjectionTest.php` — AR/EN + RTL/LTR ERP Center rendering, enabled/entitled/uncertified hiding, wrong-version hiding, exact-version healthy reappearance, unhealthy-probe hiding, genuine registered-owner module diagnostics, forged-owner denial, permission-denied direct HTTP 403 and read-only navigation/audit count.
- Related existing tests: `V2SidebarModuleEntitlementAvailabilityTest`, `EnterpriseModuleOperationalReadinessTest`, `PlatformOwnerAuthorityTest`, `V2SidebarNoRbacEscalationTest`, `V2SidebarMetadataTest`, and upstream `V2ErpSidebarOperationalBoundaryTest` once #2839 merges.
- Test environment must explicitly certify any expected operational module version. Do not add runtime default certifications; `backend/config/enterprise_module_readiness.php` intentionally defaults to an empty map.
- Required checks to verify on **this PR's final head**, not earlier commits: Repository Policy, FOODEX V2 Isolation Gate, Laravel syntax/Pint/PHPStan/security/OpenAPI and Feature suite, AR/EN localization, MySQL/Redis fresh-install/upgrade/recovery, and FOODEX Required CI Gate. A merge is not proof of browser acceptance.
- Independent genuine V2 staging QA still required before closing Issue: 320/360/390/412/430/768/1024/1280px widths; AR RTL/EN LTR; keyboard, focus, screen reader; direct-URL negative tests after #2839 integration; exact role, store, disabled/health/failure conditions. Do not claim screenshot or browser evidence without running it.

## Surface impact matrix

| Surface | Change | Reason and acceptance evidence |
| --- | --- | --- |
| Laravel Backend | **Affected — Dashboard read-only Sidebar projection** | Same existing canonical `ModuleOperationalReadiness`, no data write/migration/API endpoint. Covered by focused tests. |
| Dashboard | **Affected — existing Sidebar link target** | Reuses existing business group, locale catalog, native responsive Sidebar controls; no markup, CSS, JavaScript or action changes. Real-browser responsive/a11y pending. |
| Customer Flutter | **Not affected** | No changes to customer auth, API, payload, routes, push/deep link, catalog, order, B2B fulfillment or Flutter source. |
| Driver Flutter | **Not affected** | No change to B2C Driver-only dispatch, task APIs, roles, payloads or Flutter. |
| Van Flutter | **Not affected** | No change to B2B Van-only dispatch, visits, fulfillment APIs, roles or Flutter. |

No production data, deployment, release, APK, Git tag or main changes are authorized. The issue stays open if independent acceptance remains outstanding.

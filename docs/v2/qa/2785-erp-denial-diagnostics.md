# V2 P034 — ERP denial diagnostics by module, entitlement and business scope

- Source: FOODEX Unified RBAC/ERP/Sidebar 2026-11-09, paragraph P034; Issue #2785; parent #2503.
- Integration target: `Foodex_V2` only. Do not promote to `main` or operate against production.
- Domain / entity: Enterprise ERP module; actors: authorized Enterprise ERP Center users with effective scoped role permissions; entry point: existing `admin.erp.index` / `admin/erp`; navigation owner: canonical `AdminNavigation` ERP Center; navigation type: existing `SIDEBAR` destination.
- Reuse → Extend: reuse the existing ERP Center card, authoritative ModuleRegistry lifecycle, ModuleEntitlementResolver and ModuleOperationalReadiness checks; add explanatory copy only to the already-visible authorized cards. No new route, Sidebar grant, admin setting or new screen.
- Diagnostic classification:
  - `module_setup`, `module_disabled`, `module_unavailable`: lifecycle does not permit business operations.
  - `entitlement_scope`: effective entitlements do not authorize this actor's platform/company/store business context. This intentionally does **not** identify other tenants, list their entitlements, or claim which of two missing prerequisites is the sole cause.
  - `uncertified`: manifest version lacks explicit operational certification; an enabled/entitled module is still blocked.
  - `unhealthy`: the certified version failed the authoritative health report.
  - `route_unavailable`: no registered business destination; fail closed rather than advertising an available card.
- Authority: `AdminNavigation::hasAnyPermissionInScope` still decides whether a card can exist at all; the ERP Center diagnostic never adds an item to a forbidden role. Existing route middleware and domain checks independently reject unauthorized direct GET/POST requests. Diagnostics only describe a denied state: they do not issue permission, change data, or bypass tenant separation or financial SoD.
- Locale and UI: AR/EN strings in existing `admin.erp_center.denial_reasons`; layout retains canonical card/tokens/responsive flow, readable text, normal DOM semantics and normal focus order in RTL/LTR. Existing `status_help` fallback remains for non-module route cards.
- Tests: `V2ErpDenialDiagnosticsTest` covers disabled/not-installed lifecycle, missing entitlement, missing exact-version certification, healthy recovery, failing health probe, non-privileged FINANCE visibility exclusion, denied direct ERP HTTP routes, both locales and direction attributes. Related existing `ErpCenterTest`, `V2ErpSidebarOperationalBoundaryTest`, `V2ErpFinanceProcurementReadinessGateTest`, `V2AllowScopeReadinessRegressionTest` and broader security tests must remain green.
- Data & security: no migrations, storage changes or persisted diagnostic data. Synthetic seeded fixtures only. No cross-tenant module information or internal IDs in visible copy. Module lifecycle and entitlement changes are *test fixtures*, not a new UI mutation.
- Customer app: **not affected** — no Flutter, public API, authentication, catalog/pricing/order or notification change.
- Driver app: **not affected** — no Flutter, GPS, driver authorization, B2C delivery or dispatch change.
- Van app: **not affected** — no Flutter, B2B-only van assignment/routing, visits or fulfillment change.
- Required evidence before merge: exact-head Repository Policy, FOODEX V2 Isolation Gate, Laravel Backend suite/Pint/PHPStan/security/OpenAPI, AR/EN localization, MySQL/Redis clean install/upgrade/recovery and `required-ci-gate` success. Run real isolated-V2 staging browser responsive/keyboard/role/scope acceptance independently before closing Issue; code-level CI alone does not prove that product-level acceptance.

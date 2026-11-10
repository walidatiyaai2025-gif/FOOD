# V2 #2796 — Hide Sidebar module links when unavailable or not entitled

Source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx P044, parent #2503. Delivery target: Foodex_V2 only; never main or production.

## Authoritative runtime contract

- User action: enter Dashboard > ERP and choose the Procurement workbench. Canonical route: admin.erp.procurement.index (/admin/erp/procurement). Required permission: procurement.suppliers.view. Server lookup is scoped to the actual B2B store/enterprise hierarchy.
- Module: procurement registered in the ModuleRegistry and ENABLED. An unavailable, not-installed or disabled module is not a working Sidebar destination. The Procurement HTTP controller rejects disabled-module entry with 404 before accessing business data.
- Entitlement: ModuleEntitlementResolver::allows('procurement', EntitlementScope::platform()) is false when no grant exists or when an explicit grant is disabled; Sidebar removes erp_procurement even if job-role permissions exist. A valid enabled module plus valid platform grant and authorized role adds the registered route. A route is not made authorized merely by appearing in a menu.
- Authorization: a FINANCE-only actor cannot use the Procurement route (403) even when the module is enabled and globally entitled; permissions, tenant scope, module lifecycle and entitlement are separate checks. Never make Sidebar visibility into a substitute for server-side authorization.
- Platform Owner exception: a separately registered owner may see a disabled module's diagnostics destination (admin.enterprise-modules.show) instead of an operational workbench link. This is intentionally not a working module link or ordinary-user authorization.

## Executable matrix

| Case | Sidebar | HTTP / independent evidence |
|---|---|---|
| B2B actor with procurement permission; module disabled | Hide erp_procurement | Procurement GET 404 for AR and EN |
| Module ENABLED; no platform entitlement | Hide erp_procurement | Authoritative resolver deny; no synthetic visible access |
| Module ENABLED; platform entitlement explicitly false | Hide erp_procurement | Authoritative resolver deny |
| Module ENABLED; platform entitlement true; authorized B2B actor | Show erp_procurement with canonical route | Procurement GET 200 using real B2B store/legal hierarchy; AR RTL, EN LTR |
| Module and entitlement enabled; FINANCE actor without procurement permission | Hide erp_procurement | Direct Procurement GET 403 in both languages |

File: backend/tests/Feature/V2SidebarModuleEntitlementAvailabilityTest.php. The test does not introduce independent role-based visibility branches or manipulate production data. It uses FreshDatabase and seeded enterprise roles, real ModuleRegistry lifecycle and ModuleEntitlementManager writes.

## Dashboard / ERP / mobile / data review

- Dashboard: navigation reflects server-known ModuleRegistry state, entitlement and permission. No UI widget or breakpoint CSS change; rendered AR RTL and EN LTR workbench screens are exercised.
- ERP: procurement data requests use real hierarchy and actual store ID; disabled-module HTTP request denied. Existing maker/checker and finance, procurement posting and audit policies remain unchanged; entitlement changes are audited.
- B2B / Retail: tests constrain an authorized wholesale actor to an existing B2B store. This issue does not grant Retail access or convert wholesale permissions into cross-store permissions.
- Customer App: no Flutter, customer API, authentication or storefront change.
- Driver App: no dispatch, assignment, GPS or Flutter API change.
- Van App: no B2B fulfillment, routes, visits or Van API change.
- Data: no destructive changes, schema migration, release artifact or production dependency. All state setup occurs in disposable test DB; no rollback of live business records required.

## Acceptance

Focused command: cd backend && php artisan test --filter=V2SidebarModuleEntitlementAvailabilityTest

Full validation: syntax/Pint/PHPStan/backend tests, MySQL/Redis deployment acceptance, localization, Repository Policy, FOODEX V2 Isolation Gate, exact-head required-ci-gate. A test file or PR alone is not completion; only after green checks on final SHA, merged Foodex_V2 commit verified and issue closed.
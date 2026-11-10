# V2 #2837 — ERP wholesale and retail support scope isolation

Source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, **P102**. Parent #2503. **Integration only: Foodex_V2**. One issue, owner, branch and PR.

## Business contract, roles, routes and placement

**Domain:** Enterprise authorization at the wholesale/retail tenancy boundary. **Personas:** authorized global `B2B_ADMIN` or `SUPER_ADMIN` Wholesale control-plane user; Retail `RETAIL_CUSTOMER_SUPPORT` operator assigned to a B2C store; and a Super Admin who explicitly enters an audited Retail support session for **one selected store**.

**Entry:** existing ERP / Administration Hub and Retail/Wholesale module pages. **Placement:** **Reuse → Extend** existing authoritative `TenantContextResolver` and `EnterpriseScopeAuthorizer`. No new UI, navigation item, endpoint, role, permission, module gate or entity. Canonical HTTP examples: `admin.b2b.module` and `admin.b2c.module`, both still protected by their real controllers.

**Finding:** `EnterpriseScopeAuthorizer::allows` previously returned true for **every** queried entity/scope whenever a SUPER_ADMIN's resolved context had `supportAccess=true`. This could make a store-A Retail support context appear to authorize a separate store-B enterprise entity. The service also did not verify that the supplied `ResolvedTenantContext::userId` matched the actual actor, or that the actor remained active at authorization time. A context snapshot is never an authorization grant independent of its actor/store.

**Fix:** Reject empty permission, invalid scope ID, inactive actor and mismatched actor/context ID before any role branch. For **Super Admin retail support**, allow only when explicit support mode is enabled *and* server-resolved `EnterpriseDataScope` contains the actual organization/company/legal-entity/business-unit/store being requested. Preserve the existing wholesale Super Admin control-plane authorization for genuine `b2b` context; other roles still require both scoped permission and enterprise data-scope containment. This is authorization-only; no role assignments, entitlements or historical/audit records are changed. The server's TenantContextResolver still enforces B2B/B2C store channel selection, assigned Retail role/store and auditable support entry.

## Acceptance and regression matrix

`backend/tests/Feature/V2ErpWholesaleRetailScopeIsolationTest.php` exercises:
- Super Admin explicitly enters **Retail store A** with support access and receives a support audit record. Requested store A allowed, B rejected with authorization 403, unknown entity type rejected.
- A context copied from or fabricated for another User ID cannot be reused by the wrong actor. Deactivation invalidates the previously valid authorization immediately, without account/role mutation. Reactivation restores correctly scoped permission.
- Assigned Retail support can access only the B2C customer page, with AR RTL and EN LTR markup, and cannot access the B2B page. An unassigned B2B operator cannot resolve a Retail tenant context; Retail operator cannot resolve B2B.
- Genuine Super Admin wholesale context for a B2B principal remains authorized; a B2C store ID is rejected as wholesale HTTP 404.
- Existing `EnterpriseDataScopeAuthorizationTest`, `EnterpriseProcurementWorkbenchTest` and canonical Finance/Procurement/Inventory suites remain in full Laravel CI for positive/negative, module entitlement and approval SoD proofs.

**Security limits:** This defense is in the service layer. It does not provide a second Sidebar visibility policy or create permissions; modules still require their existing readiness/capabilities/entitlement and server HTTP checks. Permission checks remain authoritative. There is no assumption that Retail support login or a visible ERP card grants another store's Financial/Inventory workflow access.

## Per-surface impact

| Surface | Classification | Proof |
| --- | --- | --- |
| Laravel Backend | **Affected**, authorization hardening | `EnterpriseScopeAuthorizer` active-actor/context-binding + retail tenant scope; tests for Store A/B and wholesale |
| Dashboard | **Affected through existing routes**, no UI or payload change | Existing ERP/Retail pages now fail closed if called with wrong store context; Arabic/English HTTP and RTL/LTR smoke proof |
| Customer Flutter | **Not affected** | No Customer API/DTO/Dart/deep-link, cart, account, or authentication protocol change; this service operates only on enterprise administrative data scopes |
| Driver Flutter | **Not affected** | No Driver delivery/wallet/GPS/status or authenticated endpoint changed |
| Van Flutter | **Not affected** | No Van-only B2B dispatch, route/collection/assignment endpoint or payload changed |

## Completion and remaining acceptance

Run exact-head Repository Policy, V2 Isolation, AR/EN localization, PHP Syntax/Pint/PHPStan/Composer audit/OpenAPI, complete Laravel tests, real MySQL/Redis setup/upgrade/recovery acceptance, and `required-ci-gate`. Record actual IDs on Issue #2837. Do not conflate CI with browser a11y: isolated V2 runtime responsive widths, keyboard/focus/screen-reader and full ERP module/finance approval acceptance require separate actual evidence before issue closure.

No `main` changes, V2 promotion, release/tag, production, SSH or ZIP publication. Maintain one PR targeting **Foodex_V2 only**.

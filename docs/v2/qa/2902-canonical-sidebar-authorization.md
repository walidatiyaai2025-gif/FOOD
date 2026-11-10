# V2 #2902 / P382 — canonical Sidebar authorization contract

Integration branch: `Foodex_V2` only. Parent: #2503. This note documents current authoritative runtime behavior, not a new Sidebar permission engine or independent authorization source.

## Authoritative evaluation pipeline

1. `User::hasPermission`, explicit ALLOW/DENY overrides and active roles supply effective permissions. An inherited global/store role is **not** permission to cross tenant/store/channel boundaries; `OperationalTenantScope` and `TenantContextResolver` resolve permitted retail stores and deny overrides.
2. `AdminNavigation::groupsFor(User)` is the only effective Dashboard navigation projection. It checks channel membership, existing registered routes, permission/scope, and module readiness/entitlements through the canonical Laravel services. It must never act as an HTTP grant.
3. `ModuleOperationalReadiness` requires certified exact manifest version and healthy checks in addition to module lifecycle ENABLED. `ModuleEntitlementResolver` validates the applicable organization/store grant. Platform Owner's diagnostics entry is **not** an operational route.
4. `V2SidebarMetadata::forUser(User)` derives from the exact `AdminNavigation` authorized tree, retaining current persisted Sidebar configuration/labels. Its `permission`, `scope`, `channel`, `module`, `readiness` fields are explanations/projections only; they must not authorize extra links or invent a new grant.
5. Real HTTP middleware/controllers use their own RBAC, tenant scope, active account, channel and module guards. Hiding a link is not enough; a forged URL must still be rejected by the backend. No authorization decision should trust a client-supplied Sidebar key.

## Scoped regression evidence

The new `backend/tests/Feature/V2CanonicalSidebarAuthorizationContractTest.php` covers:

- Same effective authorized item keys in `AdminNavigation` and `V2SidebarMetadata`, across wholesale B2B, retail store-scoped B2C, and unprivileged accounts in both AR/RTL and EN/LTR contexts
- All projected route names actually registered, including nested keys; no newly invented route or untrusted URL is permitted by metadata
- Retail-only accounts do not expose wholesale Dashboard links, and B2B-only accounts do not expose retail Dashboard links
- Explicit global DENY of `catalog.view` overrides inherited B2B role grants, removing `catalog_management` from both tree and metadata while preserving the audit-relevant deny record
- Roleless users get HTTP 403 on `admin.sidebar-layout.index`; retail-only users get HTTP 403 on wholesale orders even after reading metadata

Existing tests remain authoritative for module exact-version lifecycle, entitlement scopes, operational route rejection and audit: `V2SidebarModuleEntitlementAvailabilityTest`, `V2SidebarOperationalReadinessProjectionTest`, `V2SidebarMetadataTest`, and `V2OperationalChannelDenyScopeTest`. This PR intentionally introduces **no second service**, no permissions, no new endpoints and no production-data mutation.

## Product surface impact

- **Dashboard:** focused read-only contract regression; no modifications to production Sidebar, Blade, CSS, JS, Controllers or RBAC configuration
- **Backend/API:** tests exercise existing HTTP authorization; no API, route, policy, migration or data model changed
- **Customer:** unaffected — no app/API payload or authentication/ordering behavior change
- **Driver:** unaffected — no driver permissions, tracking, route, dispatch or app build change; B2C DRIVER ONLY remains
- **Van:** unaffected — no wholesale dispatch, visits, order or van app change; B2B VAN ONLY remains

## Acceptance and non-goals

Merge only after **exact-head** Repository Policy, V2 Isolation, full Laravel including these new tests, PHP syntax/Pint/PHPStan/security, localization AR/EN and real MySQL/Redis install/upgrade/recovery all PASS. Independently validate real staging browser (responsive RTL/LTR, keyboard and screen-reader focus), live authorization for scoped users/operational modules and runtime health before closing Issue #2902. This PR cannot itself certify those external conditions. Never target `main`, deploy production, delete live branches or expose account identities.

### Explicit Mobile impact review (CI contract)

Customer / Driver / Van: no application binaries, Flutter source, mobile APIs, user permissions, order lifecycles or payload contracts have changed. Customer remains unchanged; Driver retains B2C-only fulfillment; Van retains B2B-only fulfillment. Backend/Dashboard changes consist exclusively of read-only regression tests and QA notes, not a runtime policy change.

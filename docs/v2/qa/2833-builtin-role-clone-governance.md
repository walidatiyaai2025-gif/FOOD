# V2 #2833 — Built-in role protection and governed custom clones (P097)

## Scope, authority, placement
Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P097, parent #2503. Existing Dashboard Security role management is the sole canonical entry point: `admin.security.index?section=roles` → `admin.security.roles.clone` / `admin.security.roles.update` / `admin.security.roles.destroy`, all authorized by `roles.manage`; service authority `RbacManager` and catalog `BuiltInJobRoleCatalog`. Navigation type `PAGE_TAB`, Reuse → **Extend** existing form and server service, no new screen or Sidebar item.

Actor: Super Admin for full-grant clones, delegated roles manager only within their actual permission ceiling; store roles remain scoped to Retail store context. Scope is an existing role's permission bundle, not an arbitrary new grant. Work is a security administration procedure and is not routine customer/dispatch operation. Existing form still uses an advanced technical role-code field for privileged security administrators; other business users do not receive this entry point.

## Backend controls
- Canonical built-in/system role cannot have its identity/permissions edited or archived even if the persisted is_system flag drifts; the catalog itself remains protected.
- Custom role creation cannot hijack reserved canonical job-role codes before a role record is provisioned; historical/archived codes remain reserved.
- Authenticated and authorized admins can clone an **active**, non-control-plane built-in job role to a distinct custom role only. Clone does **not** assign that role to any user/store, change any original grants, or bypass `assertActorCanGrantPermissions` and `assertRolePermissionDomain`.
- Privileged `SUPER_ADMIN` or `PLATFORM_OWNER` source may not be cloned. Inactive or archived sources cannot be resurrected through clone.
- A clone writes original role-created audit plus explicit source-to-clone provenance, atomically in DB transaction. Errors roll back the mutation.
- The existing role screen hides clone affordance for control-plane role sources, including at narrow widths; direct POST still rejects them.

## Per-surface impact
| Surface | Classification | Evidence |
| --- | --- | --- |
| Laravel backend RBAC | Affected | `RbacManager` authoritative mutation rules; no new endpoint, permission or DB schema |
| Dashboard security page | Affected | existing roles tab removes an unsafe affordance; CSRF, form, active role display, AR/EN and native keyboard controls preserved |
| Customer Flutter | Not affected | no Customer-specific API, mobile route, auth token, data or binary changes |
| Driver Flutter | Not affected | no driver API, assignment, delivery/fulfillment or mobile source changes |
| Van Flutter | Not affected | no van API, route assignments, collection or mobile source changes |

B2B → VAN ONLY, B2C → DRIVER ONLY is unaffected. No finance SoD role is automatically assigned; custom clones are inert until separately authorized assignment.

## Acceptance and remaining evidence
Feature tests `V2BuiltInRoleCloneGovernanceTest` cover real HTTP authorized clone, source audit, immutable source, forbidden PATCH/DELETE, protected control-plane clone with AR RTL and EN LTR, inactive source, delegated operator overgrant, permission and store scope isolation, roleless 403. Existing `EnterpriseRbacTest`, `BuiltInJobRoleCatalogTest`, `V2RoleCodeHistoryTest`, `V2CustomRoleOverrideIsolationTest` are regression authorities.

Required exact-head CI: Repository Policy, FOODEX V2 Isolation Gate, FOODEX Required CI Gate, Backend syntax/Pint/PHPStan/security/OpenAPI, full Laravel tests, MySQL/Redis install/upgrade/recovery. Record actual run IDs and merge SHA in Issue. Do not claim screenshot/browser keyboard/focus/ARIA responsive verification unless independently executed on isolated V2 staging at 320/360/390/412/430/768/1024/1280 px, in both locales. Keep Issue OPEN if this acceptance remains outstanding.

**Owner lock:** only merge into `Foodex_V2`; never into `main`, never tag/release/deploy without a new formal instruction.

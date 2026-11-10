# FOODEX RBAC Administration

FOODEX authorization is server-authoritative. Dashboard visibility is a usability layer only; protected API and web mutations must pass Laravel Gate checks and, where applicable, store scope.

## Role model

Roles have an immutable code and scope, display name, optional description, active state, optional archival timestamp, and system/custom marker. Supported role scope values are: `global`, `store`, or `both`. System roles defined in `ROLE_ARCHITECTURE.md` cannot be deleted. `SUPER_ADMIN` is always active, global and unrestricted. The last active SUPER_ADMIN account or assignment cannot be removed.

Custom roles can be created, cloned, edited, activated/deactivated and permanently archived only after all assignments are removed. Archival deactivates a role while retaining its original code, row, permissions and audit history; archived roles cannot be reactivated, updated, cloned, re-archived or have their codes reused. Role scope is immutable after creation; use a new code for a different scope. A delegated role administrator cannot grant permissions outside the administrator's own effective permission set, preventing privilege escalation.

## Effective permissions

Global assignments grant only roles whose scope is `global` or `both`. Store assignments grant only roles whose scope is `store` or `both`, and only for the selected store. Inactive roles grant nothing. Unknown permission codes are deny-by-default because Gates are registered only from the declared permission catalog.

## User onboarding and status

The canonical Security workspace supports audited user onboarding for operators with `users.roles.manage`. A new user is created together with at least one initial authorized role assignment in a single transaction: a global business role, a scoped role for one active Retail/B2C store, or both. Store-only onboarding grants **no implicit global role**. The form presents only grantable, currently available roles and human-readable store/role combinations, while the server independently validates role scope, store activity, built-in-role readiness and the actor's grant authority through the same RBAC manager used for later role changes. Rejected assignments roll back user creation, so delegated operators cannot onboard a user with authority above their own or across unrelated stores. Passwords use the User model's hashed cast and are never written to audit payloads.

The distinct `users.delete` permission is registered for protected user offboarding and is **not** implied by `users.view`, `users.roles.manage`, or `users.status.manage`. It is deliberately not granted to default operational roles. Merely registering the permission does not enable destructive HTTP endpoints: administrative deletion/archival must remain unavailable until the separate protected-lifecycle, dual-confirmation, reason, dependency-check and recovery requirements are implemented and reviewed. Both Dashboard and API handlers must enforce `users.delete` independently before any future user-deletion mutation, plus target-scope and last-administrator protections. Store/retail roles must not acquire global user-deletion authority from overrides.

Authorized administrators can activate or deactivate accounts with `users.status.manage`. Deactivation stores the timestamp and optional reason, revokes Sanctum personal access tokens immediately, and is also enforced on each authenticated API/dashboard request. Reactivation restores access without recreating the user.

## Audit events

The security center records `user.created`, `user.status_changed`, `user.roles_changed`, `role.created`, `role.updated`, and `role.archived` through the shared audit logger. Historical `role.deleted` events remain unchanged; V2 does not rewrite old audit records.

## Administration surfaces

- Dashboard: `/admin/security` with task-focused Users, Roles & Permissions, Security & Governance, Sessions, and Audit tabs.
- API: `/api/v1/admin/security/*`
- Read access: `security.view` or `users.view` where explicitly supported.
- Mutations: `roles.manage`, `users.roles.manage`, and `users.status.manage`.

All security surfaces support Arabic/English administration, and the dashboard respects RTL/LTR direction.


## Enterprise data scopes, limits and delegations

The existing Laravel Gate/RBAC model remains the permission authority. Enterprise security controls add server-side constraints and temporary authority without replacing Gates or StoreContext:

- role data scopes may constrain a role to platform, organization, company, legal entity, business unit, or store scope;
- approval limits may constrain a role's approval permission by business scope, currency, and maximum amount;
- effective-dated delegations grant only a permission the delegator still holds in the requested scope and expire automatically at `ends_at`;
- delegation does not chain: delegated authority is resolved against the delegator's direct current authority;
- privileged scope, limit, and delegation mutations use the shared Enterprise Workflow engine with maker/checker separation;
- approved mutations are applied exactly once and recorded through the shared audit logger;
- absent Enterprise constraints preserve the existing RBAC behavior for backward compatibility.

Dashboard administration is available from `/admin/security/enterprise`, linked from the canonical Security Center. The page exposes business-readable scope choices and never asks administrators to type internal database IDs or JSON.

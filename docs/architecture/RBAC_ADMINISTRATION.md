# FOODEX RBAC Administration

FOODEX authorization is server-authoritative. Dashboard visibility is a usability layer only; protected API and web mutations must pass Laravel Gate checks and, where applicable, store scope.

## Role model

Roles have an immutable code, display name, optional description, active state, system/custom marker and scope: `global`, `store`, or `both`. System roles defined in `ROLE_ARCHITECTURE.md` cannot be deleted. `SUPER_ADMIN` is always active, global and unrestricted. The last active SUPER_ADMIN account or assignment cannot be removed.

Custom roles can be created, cloned, edited, activated/deactivated and deleted after their assignments are removed. A delegated role administrator cannot grant permissions outside the administrator's own effective permission set, preventing privilege escalation.

## Effective permissions

Global assignments grant only roles whose scope is `global` or `both`. Store assignments grant only roles whose scope is `store` or `both`, and only for the selected store. Inactive roles grant nothing. Unknown permission codes are deny-by-default because Gates are registered only from the declared permission catalog.

## User status

Authorized administrators can activate or deactivate accounts with `users.status.manage`. Deactivation stores the timestamp and optional reason, revokes Sanctum personal access tokens immediately, and is also enforced on each authenticated API/dashboard request. Reactivation restores access without recreating the user.

## Audit events

The security center records `user.status_changed`, `user.roles_changed`, `role.created`, `role.updated`, and `role.deleted` through the shared audit logger.

## Administration surfaces

- Dashboard: `/admin/security`
- API: `/api/v1/admin/security/*`
- Read access: `security.view` or `users.view` where explicitly supported.
- Mutations: `roles.manage`, `users.roles.manage`, and `users.status.manage`.

All security surfaces support Arabic/English administration, and the dashboard respects RTL/LTR direction.

## Enterprise security governance

Wave 1 extends the existing Security Center; it does not replace Laravel Gates, `User::hasPermission`, StoreContext or the shared Enterprise Workflow engine.

Enterprise policy changes use `enterprise.security.change` with a mandatory independent `roles.manage` review step. The requester cannot approve their own change. The approved state transition and the resulting policy mutation are committed together; retries remain idempotent through the workflow request token.

### Data scopes

`enterprise_user_data_scopes` adds effective-dated organization, company, legal-entity, business-unit and store boundaries. Existing Wave 0 workflows retain their legacy direct-RBAC behavior until an enterprise scope is configured for an actor. Once configured, organization-level workflow decisions are deny-by-default outside that actor's active scope.

### Approval limits

`enterprise_user_approval_limits` binds an actor, workflow key, currency and business scope to a decimal ceiling. Limits are opt-in for existing workflows. When a matching policy exists, a request with authoritative `amount` and `currency` context cannot be approved above the configured ceiling or through an unmatched scope.

### Temporary delegation

`enterprise_user_delegations` delegates one permission for an explicit business scope and start/end window. A delegation is valid only while:
- the delegate is active;
- the delegation is not revoked and is inside its effective window;
- the delegator is still active and still owns the delegated permission in that scope;
- the delegator's own Enterprise data scope and approval limit still permit the workflow.

Expiry is enforced at authorization time, so no background daemon is required. Revocation and all policy mutations remain maker/checker protected.

### Audit

The shared audit logger records workflow request/decision events plus applied policy events:
- `enterprise.security.data_scope.changed`;
- `enterprise.security.approval_limit.changed`;
- `enterprise.security.delegation.created`;
- `enterprise.security.delegation.revoked`.

The Dashboard renders business labels for scope/user context and does not expose raw policy payload JSON.


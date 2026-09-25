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

# Management Dashboard Shell

FOODEX uses one Laravel Management Web Dashboard. B2B and B2C are role-aware navigation channels inside the same application, not separate admin projects.

## Routes

- `/admin` — shared dashboard shell.
- `/admin/b2b/dashboard` — wholesale channel placeholder.
- `/admin/b2c/dashboard` — retail channel placeholder.

These routes establish the navigation boundary only. Product screens remain issue-scoped work.

## Authorization

- `SUPER_ADMIN` can see both channels.
- `B2B_ADMIN` is limited to the B2B channel.
- `B2C_STORE_ADMIN` reaches the B2C channel only through an explicit store-role assignment.
- Shared operational dashboard roles can enter the channels declared in `config/admin.php`.
- Driver-only roles are denied management-dashboard access.
- UI visibility is never treated as backend authorization. Feature controllers must still use permission checks and mandatory store scoping.

## Localization

Arabic is the default shell locale and renders RTL. English renders LTR. The authenticated user's supported locale selects the shell language; unsupported values fall back to the application locale.

# FOODEX 1.0.116

## Dashboard update

FOODEX 1.0.116 is a Dashboard/backend-only repair for existing installations where Customer Service dispatch authorization remained stale after earlier role-configuration changes.

- Backfills `orders.dispatch` for `CUSTOMER_SERVICE_MANAGER`, `CUSTOMER_SERVICE_SUPERVISOR` and `CUSTOMER_SERVICE_AGENT`.
- Restores the Driver/Van assignment control in Order Management when the effective RBAC state is current.
- Does not grant `orders.manage`, driver administration, or live tracking privileges to Customer Service Supervisor/Agent.
- Customer, Driver and Van mobile identities are unchanged.

## Published Dashboard artifact

- Target version: `1.0.116`
- Minimum current version: `1.0.6`
- SHA-256: `e0a3316a71b79835d04a013064830d73383c46f10457cfe084c8a146e7994c8a`

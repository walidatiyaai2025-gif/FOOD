# Order Lifecycle Foundation

Orders have an explicit channel: B2C or B2B. Status transitions will be backend-authoritative domain actions that append order_status_history and audit records. Clients cannot set arbitrary statuses.

The production state machine is intentionally not implemented during bootstrap.

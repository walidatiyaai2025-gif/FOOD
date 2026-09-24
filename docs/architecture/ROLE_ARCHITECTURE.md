# Role Architecture

Dashboard roles at minimum:
- SUPER_ADMIN — whole platform.
- B2B_ADMIN — wholesale operations.
- B2C_STORE_ADMIN — only explicitly assigned store(s).
- OPERATIONS.
- INVENTORY.
- FINANCE.
- CUSTOMER_SUPPORT.

Driver roles:
- B2C_DRIVER — retail deliveries only.
- B2B_DRIVER — wholesale shipments only.

Customer identities:
- B2C Guest browses without authentication.
- B2C Customer authenticates for checkout, synced favorites/addresses and order history.
- B2B Customer has no public self-registration; authorized dashboard users create/approve the account.

UI visibility is not authorization. Server-side policies, permission checks and store scope are mandatory.

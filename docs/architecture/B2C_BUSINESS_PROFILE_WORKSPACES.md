# B2C Business Profile Workspaces

## Authoritative contract

FOODEX keeps **channel** and **business profile** separate.

- `store_types.code = B2C` identifies the B2C channel.
- `stores.business_profile` is the authoritative operating model for a B2C store and accepts exactly:
  - `RETAIL_STORE`
  - `RESTAURANT`
- Non-B2C stores must not carry a B2C business profile.
- A missing or unknown profile fails closed at workspace boundaries.

The migration backfills legacy B2C stores from the pre-existing Restaurant SaaS master store entitlement. A durable audit row records the classification source. Re-running the backfill is idempotent.

## Provisioning

The B2C creation wizard asks for Business Type in the first step. The choice is persisted with the Store identity.

**Retail Store**
- Retail workspace only.
- Existing wholesale identity is retained for compatibility with existing merchant infrastructure, but Restaurant-only workspace routes remain unavailable.
- Restaurant SaaS controls are not shown.

**Restaurant**
- Restaurant control-plane records and Smart Ordering site are provisioned automatically.
- The canonical `retail_wholesale_accounts` linkage is reused as the Restaurant's wholesale buyer identity.
- No second customer, cart, order, invoice, or payment ledger is introduced.

The legacy Restaurant SaaS toggle route remains compatibility-only for old automation. Enabling it converts a legacy B2C profile to `RESTAURANT` once. Disabling the legacy entitlement never converts the profile back to Retail.

## RBAC

Built-in store roles can declare `business_profiles`.

- Retail roles are assignable only to `RETAIL_STORE`.
- Restaurant roles are assignable only to `RESTAURANT`.
- `B2C_STORE_ADMIN` is intentionally shared across both profiles.
- Server-side assignment validation is authoritative; UI filtering is only a convenience layer.

## Workspace isolation

Retail workspace modules:
- Dashboard
- Products
- Inventory
- Retail Orders
- Customers
- Promotions
- Reports
- Settings

Restaurant workspace:
- Dashboard / overview
- Menu
- Customer Orders
- Operations
- Customers / CRM
- Marketing
- Reports
- Settings
- Wholesale Purchases

Restaurant routes require both the Restaurant profile and the Restaurant entitlement. Retail B2C workspace routes enforce their own profile allowlist. Crafted cross-profile URLs fail closed.

## Wholesale Purchases

The Restaurant buyer area composes the existing B2B infrastructure:
- Wholesale Catalog
- Purchase Cart
- Purchase Orders
- Supplier Invoices
- Supply / Delivery Status

The same linked `b2b_customer_id` is used end-to-end. Existing pricing, cart, order, invoice, payment, replenishment, and delivery truth remains canonical.

## Post-login landing

`B2cWorkspaceLandingResolver` resolves a single-store B2C user by authoritative profile:

- `RETAIL_STORE` -> Retail dashboard.
- `RESTAURANT` -> Restaurant workspace.
- Multi-store users are not silently assigned an arbitrary store.

Password, WhatsApp OTP, SSO, MFA, and direct `/admin` fallback all converge through the same landing rule.

## UX and responsive contract

Restaurant navigation exposes a task-first primary strip:
- Customer Orders
- Menu
- Operations
- Wholesale Purchases
- Settings

Customer Orders and Wholesale Purchases are intentionally separate concepts. The dashboard keeps primary KPIs and urgent operational exceptions visible while deeper analytics remain a drill-down. Menu editing remains focused/bounded. Shared responsive breakpoints cover 1280/1024/768/430/390/360-class layouts and preserve AR/EN RTL/LTR behavior.

## Operational verification

Before release:
1. Run required repository policy and FOODEX CI on the exact head.
2. Provision one Retail Store and one Restaurant.
3. Verify login landing for each.
4. Attempt cross-profile routes and role grants; they must fail.
5. Verify Restaurant Wholesale Purchases uses the existing `retail_wholesale_accounts` / B2B linkage.
6. Verify legacy backfill classification and audit rows.
7. Check AR/EN and narrow viewport navigation/control reachability.

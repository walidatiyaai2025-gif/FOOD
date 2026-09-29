# Role Architecture

The tenancy/ownership authority is `docs/architecture/MULTI_TENANT_ARCHITECTURE.md`.

## Platform and dashboard roles

- **SUPER_ADMIN** — platform control plane. Creates/activates/deactivates retail stores, assigns store-scoped managers/roles, manages platform lookups/settings and may explicitly enter an audited store context for support.
- **B2B_ADMIN** — wholesale principal administration only. Does not inherit retail tenant access.
- **B2C_STORE_ADMIN** — complete administration only for explicitly assigned B2C store(s).
- **OPERATIONS / INVENTORY / FINANCE / CUSTOMER_SUPPORT** — permissions may be global only when explicitly approved; tenant operational roles should normally be assigned through `user_store_roles`.
- Additional Catalog/Orders/Marketing/Reporting roles may be introduced as store-scoped roles without weakening the tenant boundary.

## Store assignment

`user_store_roles` is the authoritative link between a user, a retail store and a store-scoped role.

Rules:
- B2C permissions require both the permission and ownership of the active/target store.
- A B2C user with one assigned store enters that store context directly.
- A B2C user with multiple assigned stores may switch only among those stores.
- B2C users never see the B2B wholesale workspace unless separately granted an explicit wholesale role.
- UI visibility is not authorization; controllers/policies/services must reject cross-store IDs.

## Driver roles

- **B2C_DRIVER** — retail deliveries only and scoped to approved retail operation/store assignments.
- **B2B_DRIVER** — wholesale shipments only.
- Driver queries and assignment mutations must never cross B2B/B2C channels.

## Customer identities and business records

Customer App commerce uses one shared authentication identity in `users` plus one `platform_customers` identity record. Business records remain separate and store/channel owned.

- **B2C Guest** browses Wholesale and Retail storefronts without authentication.
- **Platform Customer** registers once from Wholesale or an exact Retail storefront and uses the same login across the platform.
- A Retail-origin Platform Customer immediately gets the source store's scoped `b2c_customer` and automatically receives an active Wholesale `b2b_customer + b2b_account` with the approved default price tier.
- Additional Retail `b2c_customers` materialize only per exact store as the same Platform Customer later performs authenticated commerce there.
- **Managed corporate/credit B2B accounts** remain an administrative Wholesale lifecycle; automatic Platform Customer Wholesale eligibility must not bypass special approval/credit policy.
- Registration origin is immutable provenance and is visible in authorized Dashboard Customer 360 views.
- One customer login never grants Dashboard/admin permissions.

A shared `customers.type` table is transitional compatibility only and must not become the authority for multi-tenant business ownership.

The detailed authority is `docs/architecture/PLATFORM_CUSTOMER_COMMERCE.md`.

## Authorization invariant

Every protected action requires:
1. permission for the action; and
2. ownership of the target entity through the current StoreContext/wholesale context.

SUPER_ADMIN is the only platform-wide role, and cross-store actions performed through it must be explicit and auditable.

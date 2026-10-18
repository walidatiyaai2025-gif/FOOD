# B2C Business Profile Workspaces

## Authoritative model

FOODEX keeps the commercial channel and the B2C operating model separate.

- `store_types.code = B2C` identifies the B2C channel.
- `stores.business_profile` is the authoritative B2C operating model.
- Supported profiles are exactly `RETAIL_STORE` and `RESTAURANT`, represented by `App\\Enums\\StoreBusinessProfile`.
- Non-B2C stores do not resolve to a B2C business profile.
- Profile-specific workspace boundaries fail closed when the selected store does not match the required profile.

## Legacy backfill

Migration `2026_10_18_114500_add_b2c_business_profile_to_stores.php` classifies legacy B2C stores once and records the result in `store_business_profile_backfills`.

- Stores with the existing Smart Ordering / Restaurant master entitlement are classified as `RESTAURANT`.
- Other legacy B2C stores are classified as `RETAIL_STORE`.
- The audit row stores the classification reason and timestamp.
- Re-running the migration path remains idempotent because already-classified stores are not reclassified.

## Provisioning

The Add Store flow requires a business profile at creation time.

### Retail Store

The Retail workspace owns product selling, inventory, retail orders, customers, promotions, reports and settings. Restaurant-only routes and Smart Ordering operations are not exposed as Retail workspace capabilities.

### Restaurant

The Restaurant profile provisions the Restaurant/Smart Ordering operating surface. Restaurant customer orders remain operational customer orders, while Wholesale Purchases is a separate buyer workflow.

## RBAC

Built-in store roles may declare a `business_profiles` allowlist.

- Retail-specific roles are limited to `RETAIL_STORE`.
- Restaurant-specific roles are limited to `RESTAURANT`.
- `B2C_STORE_ADMIN` is intentionally shared by both profiles.
- `BuiltInJobRoleCatalog` validates profile identifiers and fails closed for profile-incompatible store assignments.

## Workspace and route isolation

Retail and Restaurant use separate navigation domains and direct-route enforcement.

- Retail users do not receive Restaurant navigation.
- Restaurant users do not receive ordinary Retail workspace modules.
- Restaurant routes additionally require Restaurant profile eligibility.
- Crafted cross-profile URLs are rejected server-side.

## Post-login landing

`B2cWorkspaceLandingResolver` selects the authorized store and then resolves the destination from its authoritative business profile.

- `RETAIL_STORE` resolves to an authorized Retail workspace.
- `RESTAURANT` resolves to an authorized Restaurant workspace.
- Requested/last/primary store resolution remains bounded to stores assigned to the user.
- Cross-profile intended URLs are not accepted.

Password, OTP/MFA and the management shell share the same profile-aware routing authority.

## Restaurant Wholesale Purchases

Restaurant procurement reuses the canonical B2B buyer infrastructure and the existing linked wholesale customer identity. The dedicated Restaurant Wholesale Purchases route does not create a second catalog, cart, order, invoice or payment ledger.

The Dashboard capability inventory contains the canonical Restaurant Wholesale Purchases route so navigation and route ownership stay explicit.

## UX contract

Restaurant management is task-first. Customer Orders, Menu, Operations, Wholesale Purchases and Settings are distinct primary tasks. Customer Orders and Wholesale Purchases are deliberately separated.

The Restaurant task navigation preserves AR/EN, RTL/LTR and explicit responsive behavior across 1280, 1024, 768, 430, 390 and 360 class widths.

## Release verification

Issue #2175 is the final convergence certification for the B2C profile split. The exact certification PR must pass both FOODEX Required CI Gate and Repository Policy before merge. Its test suite verifies the merged child evidence for profile data, provisioning, RBAC, workspace isolation, procurement, login routing and Restaurant task-density behavior.

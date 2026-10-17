# Stores & Channels Completion Contract (#1979)

## Placement

Canonical surface: **Admin → Stores → /admin/retail-stores**.

Decision: **Reuse → Extend → Compose**. #1979 does not create a second Stores module, channel ledger, order ledger, inventory ledger, integration registry, or reporting engine. The existing Retail Store Provisioning surface becomes the Stores & Channels control center and retains its existing create/edit/role/support actions.

## Authority

- Store identity and active access: `stores` + `store_types`.
- B2C channel: canonical Retail store state.
- B2B linkage: `retail_wholesale_accounts` + `b2b_accounts`.
- Restaurant channel: `smart_ordering_sites`; branches remain `smart_ordering_branches`.
- External marketplaces/POS: only registered `restaurant_saas_integrations`. No marketplace is represented as a native FOODEX channel.
- Orders/revenue: `orders`; revenue is never summed across currencies. Dashboard and report rows preserve the canonical `currency`.
- Catalog readiness: active `store_products`.
- Inventory readiness/availability: `warehouses` + `inventories`.
- Delivery readiness: active `store_service_zones`.
- Payment readiness: canonical configured `checkout.payment_methods`.
- Roles/owners: `user_store_roles` and the existing Retail/Wholesale owner link.
- Audit: `audit_logs`.

## Dashboard definitions

The default period is the latest 30 calendar days and the interactive range is capped at 366 days.

KPIs:
- active / inactive Retail stores;
- sales today by currency;
- sales in selected period by currency;
- order volume;
- out-of-stock/availability exceptions;
- degraded/suspended registered integrations;
- stores whose activation readiness is complete.

Charts:
- store revenue/order performance, normalized within currency;
- channel revenue/order performance, normalized within currency;
- lifecycle/readiness health distribution;
- store creation growth;
- out-of-stock exceptions by store;
- failed integration-event trend when authoritative integration events exist.

All list/report surfaces are bounded. The canonical store list remains server-paginated at 25 rows.

## Store / Branch 360 and readiness

The existing store accordion is extended in place. It shows:
- identity, lifecycle and readiness score;
- B2C, B2B and Restaurant channel state;
- catalog product count;
- positive inventory positions;
- active delivery zones;
- integration total/degradation;
- audit-event count;
- owner/roles using the pre-existing role surface.

Activation readiness requires:
1. identity;
2. primary owner/manager;
3. active catalog product;
4. positive inventory position;
5. at least one canonical checkout payment method;
6. active delivery coverage;
7. no degraded/suspended registered integration.

A missing optional Restaurant/marketplace connector is **not** fabricated as a failure. An installed degraded connector is a failure.

## Lifecycle

`store_channel_operational_profiles` records only the control-plane lifecycle state, reason, actor and change time. It does not replace `stores.is_active`.

Transitions:
- **activate** → requires every readiness check and writes `stores.is_active=true`;
- **suspend** → writes `stores.is_active=false`;
- **close** → requires no open operational orders, writes inactive state, and is final.

Every transition requires an operator reason, executes server-side under SUPER_ADMIN authorization, synchronizes the existing Retail/Wholesale account through `RetailWholesaleAccountService`, and emits `retail_store.lifecycle_changed` audit evidence.

## Reports and export

The control center exposes four bounded operational datasets:
- Store performance;
- Store readiness / health;
- Availability exceptions;
- Integration / channel exceptions.

CSV export is served from the same authorized control surface. The existing Reports Center remains the canonical general store/channel analytics destination, and Enterprise BI remains the scheduled-report destination; #1979 adds no second scheduler/export ledger.

## Localization, responsiveness and accessibility

All new visible copy is in `lang/en/store_channels.php` and `lang/ar/store_channels.php`. The control center composes inside the existing responsive Admin shell and targets the repository matrix from 320px through 1280px+. Charts include tabular/text authority, lifecycle controls are native server forms, and state is not client-authoritative.

## Validation

Required merge evidence:
- repository policy;
- localization parity;
- PHP lint/static analysis/security audit;
- migrations and backend feature tests including #1979 lifecycle/readiness/export coverage;
- required dashboard runtime visual evidence in AR/RTL and EN/LTR;
- exact-head required CI gate green after synchronization with latest `main`.

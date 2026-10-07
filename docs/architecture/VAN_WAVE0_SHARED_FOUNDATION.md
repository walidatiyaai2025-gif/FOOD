# Van Wave-0 shared foundation

Issue: #938

This lane establishes the standalone `apps/van_app` shell without duplicating existing FOOD business domains.

## Contracts

- Van App is a standalone Flutter application.
- Backend APIs remain authoritative for commerce, routing, inventory and finance.
- No fixed Van, route, territory, schedule, currency, threshold or business identifier is embedded in the app.
- Arabic and English are supported from the application root.
- Operational areas use tabs before long stacked scrolling.
- Future Van finance consumes the shared Collection/Custody/Remittance domain; it must not create a Van-only ledger.
- Future field order capture consumes canonical customer, catalog, pricing and order APIs.
- Runtime API base URL is build-configurable through `FOODEX_API_BASE_URL`.
- Preview contract identity is `shared-flutter-v1`, matching existing mobile preview foundations.

## Wave-0 boundary

The scaffold intentionally contains no production routing, collection, inventory or delivery mutation. Those capabilities land in dependent atomic lanes after their authoritative backend contracts exist.

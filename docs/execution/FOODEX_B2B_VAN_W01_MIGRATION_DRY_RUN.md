# W01 — B2B Van Fulfillment Actor Contract & Migration Dry-Run

Issue: #1192  
Umbrella: #1190

## Locked actor contract

- B2B / Wholesale orders are fulfilled by **Van**.
- B2C / Retail orders are fulfilled by **Driver**.
- Order source does not change the fulfillment actor.
- Cross-channel manual assignment is rejected server-side.
- `OrderTerritoryRoutingService` is Van routing and rejects B2C orders.

## Van execution state

Routing ownership remains in `order_van_assignments`. Delivery execution state is stored independently in
`order_van_execution_states`, keyed one-to-one to the Van assignment. Creating or reusing an active Van
assignment initializes the execution state to `assigned` without rewriting an existing execution lifecycle.

This separation prevents routing reassignment state from being overloaded with delivery execution transitions.

## Production-like dry-run

Run from `backend/`:

```bash
php artisan foodex:audit-b2b-van-cutover
php artisan foodex:audit-b2b-van-cutover --json
```

The audit is read-only. It inventories open orders and reports:

- active Driver assignment on B2B;
- Driver dispatch ownership on B2B;
- multiple active Van assignments on B2B;
- B2B that still requires Van routing/dispatch;
- active Van assignment on B2C;
- Van dispatch ownership on B2C.

Rows are ordered by Order ID and assignment IDs, so repeated runs against the same database state are deterministic.

## Cutover / rollback contract for W14

W01 performs **no destructive backfill**. The later cutover lane must:

1. preserve historical Driver B2B records;
2. end active contradictory execution with audit rather than delete it;
3. re-route B2B to Van or explicit `awaiting_dispatch`;
4. end B2C active Van contradictions before restoring Driver ownership;
5. be retry-safe and deterministic from current database state.

Rollback of W01 itself is schema-safe: the new execution-state table can be dropped by the migration down path,
and the dry-run command never mutates operational data.

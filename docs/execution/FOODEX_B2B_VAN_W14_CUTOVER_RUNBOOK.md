# FOODEX B2B -> Van W14 Cutover Runbook

Issue: #1205
Parent: #1190
Locked policy: **B2B / Wholesale -> Van only; B2C / Retail -> Driver only.**

## Purpose

W14 converts existing open fulfillment work to the locked actor policy without deleting historical assignments. It builds on the W01 read-only inventory and the canonical W03/W05 Van routing/execution services.

The cutover is intentionally **not** a blind data migration. Every applied run receives a UUID, an immutable per-order before/after snapshot, audit events, deterministic retry behavior, and a conflict-safe rollback path.

Terminal orders (`cancelled`, `canceled`, `completed`, `delivered`, `refunded`, `void`, `voided`) are never rewritten by W14.

## 1. Read-only preflight

Either command is safe and performs no assignment writes:

```bash
php artisan foodex:audit-b2b-van-cutover --json
php artisan foodex:cutover-b2b-van --json
```

Review at minimum:

- `b2b_active_driver`
- `b2b_dispatch_driver`
- `b2b_multiple_active_vans`
- `b2b_requires_van_routing`
- `b2c_active_van`
- `b2c_dispatch_van`

An explicit B2B `awaiting_dispatch` state with no assignee is a valid safe outcome and is **not** reported as an unresolved actor contradiction.

## 2. Apply

```bash
php artisan foodex:cutover-b2b-van --apply --json
```

For each non-terminal B2B order W14:

1. preserves all DriverAssignment rows;
2. ends active legacy Driver execution as `reassigned` and writes audit evidence;
3. releases stale Driver-owned dispatch state;
4. preserves a valid canonical Van assignment when one already exists;
5. otherwise reuses `B2BOrderRoutingCoordinator` / `OrderTerritoryRoutingService`;
6. produces either an active Van assignment or explicit `awaiting_dispatch`;
7. writes a per-order before/after snapshot in the cutover ledger.

For each non-terminal B2C order W14:

1. ends contradictory active Van assignments without deleting them;
2. restores the active Driver as dispatch owner when one exists;
3. otherwise leaves the order explicitly unrouted for the Retail/Driver workflow;
4. writes audit evidence for the cleanup.

The command returns the run UUID, snapshot count, preflight counters, postflight counters, and status.

## 3. Retry / idempotency

Re-running `--apply` is safe. Already-correct Van assignments and explicit `awaiting_dispatch` states are preserved rather than churned. A no-op rerun produces zero order snapshots.

Operational reassignment after cutover remains the responsibility of the normal dispatch/routing flows; the cutover command does not continuously rebalance already-safe work.

## 4. Rollback

Use the exact run UUID returned by `--apply`:

```bash
php artisan foodex:cutover-b2b-van --rollback=<run-uuid> --json
```

Rollback is non-destructive:

- pre-cutover Driver assignment status is restored;
- pre-cutover Van assignment rows are restored;
- Van rows created by cutover are **ended**, never deleted;
- pre-cutover dispatch state is restored;
- cutover snapshots and audit history remain readable.

Rollback refuses to overwrite an order whose fulfillment state changed after cutover (for example, a Van moved the order to `picked_up`). Such orders are recorded as rollback conflicts and the run becomes `partial_rollback` rather than destroying newer operational truth.

## 5. Verification gate

Targeted backend gate:

```bash
php artisan test \
  tests/Feature/B2BVanFulfillmentCutoverTest.php \
  tests/Feature/B2BVanFulfillmentMigrationAuditTest.php
```

Required evidence:

- B2B Driver history preserved and active Driver work ended;
- B2B assigned to Van when routing can resolve one;
- unresolved B2B lands in explicit `awaiting_dispatch`;
- B2C Van contradictions removed while Driver work remains intact;
- a second cutover run is a semantic no-op;
- rollback restores untouched post-cutover work;
- rollback refuses to overwrite newer Van execution activity;
- default CLI invocation remains read-only.

## 6. Operator safety rules

- Run the read-only preflight first.
- Keep the returned run UUID with release evidence.
- Never delete historical Driver/Van rows to ?clean? a contradiction.
- Do not rollback a run with conflicts by manually forcing database rows; resolve the operational order explicitly.
- Do not close umbrella #1190 from this lane. W16 remains the integrated closure authority.

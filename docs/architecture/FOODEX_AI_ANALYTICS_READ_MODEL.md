# FOODEX AI Analytics Read Model Contract

Issue: #2213  
Parent: #2208  
Depends on: #2211 security boundary, #2212 semantic metrics  
Business timezone: `Asia/Kuwait`

## Authority and safety boundary

The analytical tables introduced here are **derived read models**, never a replacement for FOODEX transactional truth. Orders, order items, payments, inventory, stock movements and retail replenishment lineage remain authoritative.

AI callers never receive database credentials and never submit SQL. `AnalyticsReadModelService` accepts typed store/channel/date inputs, re-authorizes them through `OperationalTenantScope` and `RetailerSellOutAccessPolicy`, then performs bounded queries against indexed aggregate tables.

Every result states `model_sql_allowed=false` and carries the canonical metric dictionary version from #2212.

## Materialized facts

`analytics_daily_commerce_facts` stores one row per store/channel/Kuwait business day:

- total and recognized orders;
- gross booked value;
- canonical gross revenue, discounts, net revenue and AOV;
- refunded/cancelled order counts and value;
- recognized order-line units;
- paid/captured payment value and refunded payment value;
- retailer wholesale purchases/units received through authoritative `retail_replenishments`;
- currency, source watermark, materialization time and explicit data-quality state.

B2B rows are `sell_in`. B2C rows are `sell_out`. They are never silently pooled or relabelled.

`analytics_inventory_snapshots` records current on-hand, reserved and available inventory with a source watermark. Historical inventory is **not fabricated**: automatic snapshots are written only for the current Kuwait day because existing source data cannot reconstruct every historical balance safely.

`analytics_pipeline_watermarks` records the latest materialized date/source timestamp and quality state per pipeline/store/channel.

## Incremental and rebuild behavior

`foodex:analytics-refresh` recomputes the rolling edge (today + previous day; first run seeds 7 days) so late updates are absorbed. The scheduled job runs every 15 minutes with overlap protection.

Explicit `--from` and `--to` invoke a backfill. Long backfills are chunked into 90-day rebuild slices and use idempotent upserts.

Examples:

```bash
php artisan foodex:analytics-refresh
php artisan foodex:analytics-refresh --store=12 --store=15
php artisan foodex:analytics-refresh --from=2026-01-01 --to=2026-06-30 --store=12
```

## Read limits, freshness and quality

Server-side limits:

- maximum query window: 90 Kuwait business days;
- maximum returned daily fact rows: 1,000;
- maximum requested stores: 100;
- direct B2B/B2C access requires both `assistant.use` and `reports.view`;
- wholesale access to retailer sell-out requires an active #2211 grant for **every** remote retailer and cannot exceed each grant's retention window.

Freshness is explicit:

- `fresh`: complete coverage; current/recent materialization is within 20 minutes;
- `stale`: coverage is complete but recent data is older than the freshness threshold;
- `incomplete`: missing expected rows or a row reports a data-quality issue such as mixed currencies.

## Performance contract

The query path is the aggregate table, not transactional scans. The primary access index is `analytics_daily_channel_date_store_idx`, with a store/date companion index. Materialization also uses `orders_store_channel_created_idx` and `payments_created_status_order_idx` while reusing the existing stock-movement index.

Targets for a warmed production database and up to 10 stores:

| Window | Max rows | Target p95 service time |
| --- | ---: | ---: |
| 7 days | 70 | <= 150 ms |
| 30 days | 300 | <= 300 ms |
| 90 days | 900 | <= 600 ms |

The hard service cap is 1,000 rows regardless of caller. CI includes a realistic-volume fixture with thousands of aggregate rows to prove the 90-day bounded path and tenant isolation. Production observability should track these targets rather than letting the LLM compensate for slow or incomplete data.

## OpenAPI

No public `/api/v1` route is added by #2213. This is an internal Laravel domain service and Artisan/scheduler contract, so the public OpenAPI schema is unchanged.

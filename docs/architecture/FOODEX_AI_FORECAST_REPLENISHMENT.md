# FOODEX AI Demand Forecasting & Replenishment

Issue: #2221  
Contract: `foodex.forecast.replenishment.v1`

## Purpose

This capability provides deterministic demand forecasts, backtesting evidence, replenishment recommendations and wholesale demand roll-ups for authorized retailer stores. It extends the existing FOODEX Assistant analytics path; it does not introduce a second chatbot or a model-authored SQL path.

## Authoritative inputs

- `RetailInventoryIntelligenceService` supplies store-scoped Retail sell-out velocity, history coverage, inventory availability, movement class and stock-risk evidence.
- `RetailReorderIntelligenceService` supplies lead-time, safety-stock, reorder-point, MOQ/increment-safe quantity and current wholesale availability/pricing evidence.
- The Assistant tool `forecast.replenishment` is read-only and is executed only after the existing RBAC, tenant/store scope and retailer sell-out consent checks.

## Forecast contract

The deterministic baseline is the existing weighted recent sales velocity. Forecast output includes:

- horizon in days;
- baseline daily units;
- predicted units;
- history days;
- confidence;
- explicit `insufficient_history` suppression for cold-start SKUs.

The default horizon is 14 days and the accepted range is 1–90 days.

## Backtesting

For SKUs with at least 14 days of history, the engine compares the most recent 7-day actual units with a prior-window daily-rate prediction derived from days 8–30. It returns:

- actual recent units;
- predicted recent units;
- signed unit error;
- absolute percentage error when the actual denominator is non-zero;
- aggregate MAPE over valid samples.

Zero actuals never cause division-by-zero and are excluded from aggregate MAPE.

## Replenishment

Replenishment remains governed by the existing deterministic reorder service. The forecast contract exposes its:

- expected lead time;
- safety stock;
- reorder point;
- target stock;
- executable or blocked recommendation;
- authoritative wholesale mapping and availability constraints.

No order is created by #2221. A recommendation is data only.

## Wholesale roll-up and double-counting

Retail recommendations are rolled up by canonical source wholesale store + source wholesale product. Each retail store/product identity contributes at most once. The roll-up exposes recommended quantity, unconstrained quantity, expected cost and contributing retailer stores.

This preserves the distinction between:

- Retail **sell-out** used as demand evidence; and
- Wholesale **sell-in** / replenishment quantities used for procurement planning.

The two are never silently relabelled or pooled as the same business metric.

## Failure and quality behavior

- Fewer than 7 history days: forecast suppressed as `insufficient_history`.
- Fewer than 14 history days: backtesting suppressed.
- Zero recent actual demand: percentage error is null rather than fabricated.
- Missing/invalid mapping, low history, overstock, no demand, pricing/account issues or supply constraints remain explicit states from the existing reorder engine.
- Provider/Ollama failure does not affect this deterministic capability or core FOODEX commerce.

## Assistant integration

Tool key: `forecast.replenishment`

- mode: `READ`
- data class: `SELL_OUT`
- live retailer consent: required
- permission: `reports.view`
- row limit: 1000
- source: `DemandForecastReplenishmentService`
- model arithmetic: prohibited

No public `/api/v1` endpoint changes are introduced, so OpenAPI is unchanged.

## Acceptance evidence

Focused tests cover deterministic repeatability, seasonal/deviation backtest behavior, zero-denominator protection, cold-start/new-SKU suppression, bounded horizons, wholesale aggregation and no automatic order creation. Existing Retail reorder tests continue to cover lead-time boundaries, stockout/overstock behavior, MOQ/increment rounding, mapping integrity and availability constraints.

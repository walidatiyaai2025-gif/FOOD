# FOODEX Wholesale Executive Intelligence Contract

Issue: #2219  
Parent: #2208  
Dependencies: #2215, #2218

## Purpose

`executive.wholesale` is the governed owner/executive read capability for wholesale. It composes the existing FOODEX Assistant business and operational read services and the canonical semantic metrics already used by the platform. It does not create a second chatbot, a model SQL path, or a parallel analytics authority.

## Authority and scope

- The tool is READ-only and registered in the existing typed `AssistantToolRegistry`.
- It is B2B-only and requires `reports.view`, `catalog.view`, and `b2b.accounts.view` in addition to the executor's normal `assistant.use` and tenant/store checks.
- Child reads are forced to `channel=b2b`; the returned commercial flow is always `sell_in`.
- Laravel/domain services compute every number. The model may narrate or prioritize the returned evidence but may not recalculate business truth.
- The optional `target_revenue` input is caller-supplied/configured evidence. If no target is supplied, the result explicitly reports `target_comparison.state=unavailable`; a target is never fabricated.

## Executive output

The result provides:

- current period and prior-period wholesale performance;
- optional target attainment;
- bounded top clients and bottom clients when the customer source is complete;
- lost-momentum clients only when both current and prior customer sets are complete within the bounded source;
- top and slow SKUs;
- stock exposure and pending wholesale operational issues when the role can read those sections;
- ranked intervention findings;
- separately typed recommendations;
- AR/EN deterministic executive summary and reusable natural-language query patterns.

Every ranked finding is `kind=fact` and includes metric/time/entity/source evidence plus a drill-through route. Recommendations are emitted separately as `kind=recommendation` and point back to the fact code they are based on. This prevents model or UI presentation from blurring observed business facts with suggested action.

## Ranking rules

Ranking is deterministic software behavior. High-impact revenue decline/no activity, stock-outs, late-order clusters and configured target gaps rank ahead of watch-level refunds or slow-SKU observations. Client lost-momentum ranking uses exact current/prior returned client revenue only when the bounded customer source is complete; otherwise the capability reports partial quality and does not invent a bottom-client or momentum ranking.

## Sell-in versus sell-out

Wholesale sell-in and retailer sell-out are never pooled.

`executive.wholesale` returns B2B `sell_in` only. Retailer B2C sell-out remains a separate protected data class and must be requested through the existing consent-aware `retailers.performance` tool. The result advertises this separation and the active-retailer-consent requirement instead of silently adding retailer sales into wholesale totals.

## Drill-through

Authoritative destinations are reused rather than adding new navigation:

- revenue/performance -> `admin.reports.index`;
- wholesale orders and clients -> existing B2B module routes;
- SKU context -> `admin.catalog.index`;
- stock exposure -> `admin.erp.wms.index?tab=reorder`.

No new Dashboard page or Sidebar item is introduced.

## Provider and fallback behavior

The capability is exposed through the same typed provider tool registry consumed by the FOODEX Assistant agent path. Provider-disabled operation remains functional because the intelligence calculation itself is server-side deterministic; provider availability affects narration/planning, not the executive facts. Once #2215's multi-tool orchestrator is present, natural-language prompts such as "analyze wholesale today and tell me where to intervene" can select this registered tool without adding a second intent/router stack.

#2218 may replace or extend lower-level deterministic forecasting/anomaly services later. The public `executive.wholesale` result contract remains stable so that dependency can land underneath it.

## API and OpenAPI

#2219 adds an internal Assistant tool and no new public `/api/v1` endpoint. Public OpenAPI is unchanged.

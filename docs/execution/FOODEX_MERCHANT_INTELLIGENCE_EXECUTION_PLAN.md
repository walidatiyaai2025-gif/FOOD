# FOODEX Merchant Intelligence + Platform Analytics Execution Plan

Status: **Authoritative mission plan — execution-ready**  
Umbrella: **#1112**  
Mission-control publication: **#1113**  
Baseline at publication: `main@a8f224bd4f1c2bae4d37355cba8bb337b0b70e77`  
Owner trigger phrase: **حرك فوودكس**

This plan converts FOODEX from a set of operational Retail/Wholesale surfaces into a connected merchant operating system without weakening the existing tenant, finance, route or authorization boundaries.

The target user is especially important: **the same person may be a Retail Store Owner and a Wholesale Buyer**. FOODEX must understand both roles through one canonical identity, present both contexts safely, and use the relationship between Retail sell-through and Wholesale purchasing to produce actionable replenishment intelligence.

---

## 1. Product outcome

When a Retail Store Owner signs in to FOODEX Dashboard, the system should answer, quickly and truthfully:

- What happened in my Retail store today?
- Which products are selling fastest?
- Which products will run out first?
- When will each product likely run out?
- When should I reorder, given my actual Wholesale delivery lead time?
- How many Retail units / Wholesale packs or cases should I buy?
- Which items should I **not** reorder because they are slow-moving or overstocked?
- What will the suggested order cost at my current authoritative Wholesale tier/pricing?
- Is the suggested purchase executable with my available credit/budget?
- Why is FOODEX recommending this action?
- Can I review the suggested purchase and add it to the existing Wholesale cart without creating a second commerce flow?

The same mission also corrects FOODEX-wide analytics scarcity by establishing a shared visualization system and rolling meaningful charts into Dashboard, Customer, Driver and Van surfaces.

---

## 2. Repository authorities and invariants

Every worker MUST read and preserve:

- `AGENTS.md`
- `docs/design-reference/DASHBOARD_UI_UX_CONTRACT.md`
- `docs/design-reference/CUSTOMER_UIUX_APPROVED_TARGET.md`
- `docs/execution/UI_ROUTE_AUTHORITY.json`
- `docs/quality/LOCALIZATION_CONTRACT.md`
- existing B2B/B2C pricing, ledger, order, invoice, tenant and authorization contracts.

The mission MUST reuse, not replace:

- `users` as authentication identity;
- `platform_customers` where platform customer identity is required;
- `b2b_customers` + `b2b_accounts` for Wholesale business ownership;
- store-scoped `b2c_customers` and existing Retail store ownership;
- `retail_wholesale_accounts` for Retail-owner ↔ Wholesale-account linkage;
- `retail_wholesale_product_mappings` including `quantity_conversion_factor`;
- `RetailWholesaleReplenishmentService`;
- `B2bAccountLedgerService`;
- existing B2B pricing/MOQ/order-increment/pack/case rules;
- existing Customer/B2B cart and checkout routes;
- existing `B2cDashboardService` and B2B reporting foundations.

Hard invariants:

1. **No duplicate login identity.**
2. **No duplicate B2B customer/account created just to satisfy UI.**
3. **Registration origin is provenance, not authorization.**
4. **Retail store data and personal Wholesale finance remain separate ownership domains.**
5. **A Retail employee does not gain the owner's personal Wholesale finance merely by having Retail dashboard access.**
6. **SUPER_ADMIN support context remains explicit and audited.**
7. **No new parallel Dashboard route.**
8. **No client-trusted pricing, credit, balance, inventory or recommendation inputs.**
9. **No LLM/AI calculation of core inventory, finance or order quantities.**
10. **All recommendations must be explainable from deterministic facts.**
11. **No backend/API-only user-facing business function counts as complete.**
12. **Every user-facing function must have a real, normally reachable, authoritative screen/action path.**
13. **No placeholder, dead CTA, orphan route, mock-only surface or undocumented-deep-link-only capability can satisfy completion.**
14. **Mutations must reconcile related screens so connected surfaces do not show contradictory state.**

---

## 3. Canonical merchant identity contract

Target identity:

```
User
 ├─ Retail ownership / store-role context
 │   └─ Retail Store (B2C)
 │       └─ inventory / sales / customers / orders / operations
 │
 └─ RetailWholesaleAccount.owner_user_id
     └─ B2bCustomer
         └─ B2bAccount
             └─ price tier / orders / invoices / ledger / credit
```

The runtime must resolve the merchant through authoritative keys and ownership records, never by name/email matching as an authorization rule.

### Visibility matrix

| Actor | Retail operational data | Owner's Wholesale account/finance | Merchant intelligence |
|---|---:|---:|---:|
| Primary owner linked to B2B account | Yes | Yes | Yes |
| Retail manager/staff | Per Retail permission | No by default | Only authorized Retail operational insights |
| B2B operator | B2B-authorized only | Per B2B permission | B2B scoped |
| SUPER_ADMIN support | Explicit support context only | Explicit authorized view | Explicit authorized view |

---

## 4. Retail ↔ Wholesale product and replenishment lineage

FOODEX already has the essential bridge. This mission makes it complete and intelligence-ready.

For each relevant item the system must be able to trace:

```
Wholesale Product
  -> Wholesale Order Item
  -> Retail Replenishment Item
  -> Retail Product
  -> Retail Inventory
  -> Retail Sales / Order Items
```

The bridge must preserve:

- exact source Wholesale product;
- exact Retail target product;
- `quantity_conversion_factor`;
- source order/order item;
- received quantity;
- unit acquisition cost;
- source Wholesale store;
- Retail receiving store;
- received timestamp;
- actor/audit provenance.

Converted packs/cases require explicit safe mapping where existing repository rules require it.

Missing mappings are a business problem to surface, not a reason to guess.

---

## 5. Inventory intelligence engine

Core calculations are deterministic backend calculations.

### 5.1 Available stock

```
available_stock = on_hand - reserved
```

Aggregate only warehouses owned by the exact Retail store.

### 5.2 Weighted sales velocity

Initial approved model:

- recent 7 days: 50% weight;
- days 8–14: 30% weight;
- days 15–30: 20% weight.

The implementation may refine weighting only with tests and documented reasoning.

Exclude cancelled/refunded demand where it does not represent realized sell-through.

Return both:

- computed velocity;
- confidence/data-sufficiency state.

### 5.3 Days of cover

```
days_of_cover = available_stock / daily_sales_velocity
```

Special states:

- zero/near-zero velocity -> no finite stockout forecast; classify separately;
- negative available stock -> inventory inconsistency/critical;
- sparse history -> low-confidence forecast.

### 5.4 Stockout estimate

```
estimated_stockout_at = business_now + days_of_cover
```

Time interpretation must be consistent with FOODEX business reporting timezone rules (Asia/Kuwait where applicable).

### 5.5 Inventory aging

At minimum classify quantities/value into:

- 0–7 days;
- 8–30;
- 31–60;
- 60+ days.

Use authoritative receipt/movement history when possible. Do not manufacture an exact age when provenance is missing; expose unknown/low-confidence state.

### 5.6 Movement classes

- critical stockout risk;
- reorder soon;
- healthy;
- overstock;
- fast mover;
- slow mover;
- no-demand/insufficient-history;
- missing Wholesale mapping.

---

## 6. Lead time and reorder engine

### 6.1 Actual lead time

Use historical merchant-specific evidence:

```
Wholesale order created/confirmed -> Retail replenishment received_at
```

Expose:

- average;
- median;
- recent lead time;
- sample size;
- confidence.

If history is insufficient, fall back to a configurable policy/default and mark the confidence/source.

### 6.2 Reorder point

Base model:

```
reorder_point = daily_velocity * expected_lead_time + safety_stock
```

Safety stock must be policy/config-driven and testable.

### 6.3 Recommended quantity

Recommended demand must be converted into an executable Wholesale purchase quantity by respecting:

- product mapping conversion factor;
- minimum order quantity;
- ordering increment;
- pack size;
- case size;
- current authoritative Wholesale availability;
- current authoritative account tier/pricing.

Example:

- Retail need = 43 units;
- mapping = 24 Retail units per Wholesale case;
- recommendation = 2 cases = 48 Retail-equivalent units.

### 6.4 Priority score

Priority should combine explainable factors such as:

- stockout risk;
- days of cover vs lead time;
- sales velocity;
- revenue contribution;
- gross-margin contribution;
- lead-time risk;
- stockout/lost-sales exposure.

The score is for ranking. The UI must still show the underlying facts.

### 6.5 Negative recommendation

The system must explicitly support **do not reorder** for:

- slow movers;
- overstock;
- deteriorating sell-through;
- excessive days of cover.

FOODEX should optimize merchant working capital, not merely maximize Wholesale order value.

---

## 7. Margin and working-capital intelligence

Use authoritative purchase/replenishment unit cost and Retail sale price.

Useful outputs:

- gross margin per Retail unit;
- gross-margin percentage;
- inventory value;
- aged inventory value;
- high-margin/fast-moving quadrant;
- low-margin/fast-moving products needing pricing review;
- high-margin/slow-moving products needing promotion;
- low-margin/slow-moving products needing stock reduction.

Never infer tax/fees/costs that are not in authoritative data.

---

## 8. Suggested Wholesale purchase plan

FOODEX may prepare, but must not silently place, a Wholesale order.

### Flow

1. Calculate recommendations.
2. Rank by business priority.
3. Revalidate current B2B price, tier, MOQ/increment/pack/case and stock.
4. Build a reviewable suggested order.
5. Apply budget/credit constraint if requested/required.
6. Let merchant adjust/remove quantities.
7. Add accepted lines into the **existing authoritative Wholesale cart**.
8. Existing checkout remains authoritative.

### Financial constraint behavior

If full recommended need = 310 KWD but executable purchasing power/budget = 175 KWD:

- show full need;
- show constraint;
- prioritize highest-value/highest-risk executable lines;
- show resulting suggested subset;
- explain why lower-priority lines were deferred.

Never double-count stored credit, available credit, receivables or invoice settlement.

---

## 9. Merchant Smart Dashboard UX

Authority remains the existing Retail/B2C Dashboard route/workspace. No parallel dashboard.

### 9.1 Compact first view

Top row: **Your store today**

- Retail sales;
- orders;
- products at stockout risk;
- inventory value at risk.

Use compact cards; add sparklines only where they add trend meaning.

### 9.2 What needs attention today

A compact prioritized intelligence panel:

- reorder now;
- reorder soon;
- missing mapping;
- slow/overstock;
- unusual demand/stock condition.

Primary action: **View purchase recommendations**.

### 9.3 My Wholesale account

Visible only to the authorized owner/account actor.

Show a compact summary of:

- customer credit;
- debt/outstanding;
- credit limit;
- available credit;
- current tier;
- latest Wholesale order;
- current-period purchases;
- due invoices.

Do not mix these values into Retail revenue.

### 9.4 Recommendation rows

Each row should expose useful business facts directly:

- product image/name/SKU;
- available stock;
- velocity;
- days of cover;
- expected stockout;
- recommended cases/units;
- expected purchase cost;
- status badge;
- confidence;
- **Why?**.

Use one compact green ellipsis menu for secondary row actions under the FOODEX grid contract. A dedicated primary reorder/add-to-cart action may be visible when it is the row's central workflow.

### 9.5 Page tabs

Business-domain navigation remains stable.

Inventory area should converge toward page-level tabs such as:

- Inventory;
- Replenishment;
- Stock movements.

Reports may expose:

- Sales;
- Products;
- Inventory performance.

Do not add technical/epic names to Sidebar.

---

## 10. FOODEX visualization system

Charts are decision tools, not decoration.

A chart is justified only when it answers a named question about:

- trend;
- comparison;
- composition;
- distribution;
- relationship;
- forecast.

A single scalar stays a KPI when a chart adds no business meaning.

### 10.1 Shared visual families

Web and Flutter should share semantics/tokens even if implementations differ:

- line / area trend;
- bar / grouped bar;
- stacked bar;
- donut/composition;
- sparkline;
- progress/range/days-cover;
- heatmap/matrix;
- forecast timeline.

### 10.2 Visual rules

- FOODEX design tokens are authoritative.
- Deep Green/FOODEX Green = primary series/action.
- Lime/Mint = positive/supporting series.
- Orange = warning/secondary emphasis.
- Red = genuine critical/error state only.
- No uncontrolled rainbow palettes.
- Color must not be the sole meaning carrier.
- Accessible label/data summary required.
- Tooltips: hover on desktop, tap on mobile.
- Drill-down opens exact filtered context, never a generic page when exact context is known.
- Charts must have loading/empty/error/stale states.
- Responsive density takes priority over oversized decoration.
- AR/RTL and EN/LTR preserve the same information hierarchy.

### 10.3 Priority Merchant charts

- Retail sales vs Wholesale purchases;
- stock-risk distribution;
- days-of-cover visual/range;
- stockout forecast timeline;
- reorder spend forecast;
- margin × sales-velocity matrix;
- inventory aging;
- useful KPI sparklines.

### 10.4 Platform-wide rollout

Evaluate and add decision-useful charts to:

- B2C Dashboard and Retail reports;
- B2B Dashboard and Wholesale reports;
- Inventory;
- Orders;
- Finance/receivables/collections;
- Customer merchant/account purchase & finance views;
- Driver delivery performance;
- Van sales/orders/collections/stock/route productivity.

No surface gets a chart merely to satisfy a chart-count target.

---

## 11. AI usage boundary

AI may summarize deterministic outputs into natural language, e.g.:

> Sales are up 14% this week. Three products need reorder now. Water is highest priority because current stock covers less time than your normal Wholesale lead time.

AI MUST NOT be the source of truth for:

- inventory quantity;
- sales velocity;
- price;
- credit;
- ledger balance;
- reorder quantity;
- stock availability;
- invoice amount;
- margin calculation.

Every AI statement about a numeric recommendation must trace back to authoritative calculated facts.

---

## 12. Live data, stale and offline contract

Dynamic intelligence cannot rely primarily on manual refresh.

Each affected surface must define:

- initial load;
- foreground updates;
- resume synchronization;
- event/poll strategy;
- de-duplication;
- stale threshold;
- offline state;
- server-authoritative conflict resolution.

Events that should invalidate/recompute relevant intelligence include:

- Retail sale/order status changes that affect realized demand;
- inventory reservation/consume/release/adjustment;
- replenishment receipt;
- Wholesale stock/availability change;
- account/pricing/tier changes;
- credit/ledger changes.

Never display stale recommendation values as current without an explicit stale state.

---

## 12A. Functional Screen & Journey Convergence

Issue **#1129 (W8B)** is a release-blocking acceptance lane that consumes the completed route/UI authority from #1100 and proves actual functional usability.

### Mandatory function-to-screen chain

Every in-scope user-facing function must prove:

```
Function
 -> Authorized entry point
 -> Canonical route
 -> Real production screen/surface
 -> Authoritative data source
 -> Executable action
 -> Persisted result
 -> Immediate truthful UI feedback
 -> Connected upstream/downstream screens reconciled
```

A missing link means the feature is incomplete.

### Screen Coverage Matrix

The repository-tracked matrix is:

`docs/execution/FOODEX_FUNCTION_SCREEN_COVERAGE_MATRIX.md`

Each row must identify the Function/Requirement ID, domain, actor, entry point, canonical route, screen, action surface, related screens, backend authority, permissions, state handling, localization/responsiveness and runtime/E2E evidence.

Allowed acceptance states are PASS / PARTIAL / FAIL / UNKNOWN / UNOWNED. Any state other than PASS is release-blocking for required in-scope functions.

### Mandatory connectivity examples

- Dashboard stock-risk KPI -> exact filtered inventory/replenishment context.
- Recommendation row -> exact product/replenishment detail -> explainability -> suggested Wholesale plan.
- Accepted recommendation -> existing authoritative B2B cart -> checkout/order -> purchase history/inventory/reporting reflection.
- Order -> dispatch assignment -> Driver/Van operational screen -> lifecycle result -> Dashboard/Customer status reflection.
- Finance summary -> invoice/statement/settlement screen -> persisted financial result -> refreshed account context.

No generic redirect is acceptable when the exact business record/context is known.

---

## 13. Atomic execution lanes

| Lane | Issue | Scope | Dependencies |
|---|---:|---|---|
| W0 | #1113 | Publish this plan + mission-control registry | none |
| W1 | #1114 | Canonical Retail Owner + Wholesale Buyer identity/authorization | W0 |
| W2 | #1115 | Product mapping + replenishment lineage integrity | W0, W1 where entitlement required |
| W3 | #1116 | Inventory intelligence engine | W2 |
| W4 | #1117 | Lead time + reorder + quantity + priority engine | W2, W3 |
| W5 | #1118 | Shared FOODEX visualization design system | W0 |
| W6 | #1119 | Merchant Smart Dashboard UX + analytics | W1, W3, W4, W5 |
| W7 | #1120 | Suggested Wholesale cart + budget/credit | W1, W4; integrate after W6 route/component authority settles |
| W8 | #1121 | Platform-wide analytics/chart rollout | W5; use atomic sub-issues if file ownership conflicts |
| W8B | #1129 | Functional Screen + Journey Convergence | W6, W7, W8; consumes #1100 route authority |
| W9 | #1122 | Integrated product/runtime/performance gate | W1–W8 + W8B |

Parallelism is allowed only where file/subsystem ownership is disjoint. Shared components/files get one owner at a time.

---

## 14. Dependency graph

```
W0
├── W1 ──> W2 ──> W3 ──> W4 ──> W6 ──> W7
│                 │       │
│                 └───────┘
└── W5 ───────────────> W6
    └───────────────> W8

W6/W7/W8 ──> W8B ──> W9
W1..W8 + W8B ──> W9
```

W5 can progress in parallel with W1/W2/W3 if shared-file fences are respected.

---

## 15. “حرك فوودكس” execution protocol

When the repository owner says **حرك فوودكس**, execute this protocol from **live GitHub state**, not chat memory:

1. Fetch `main` SHA.
2. Read #1112, this plan, and `FOODEX_MERCHANT_INTELLIGENCE_STATUS.json`.
3. Fetch W1–W9 issues, current open PRs and relevant branches.
4. Reconcile leases under `AGENTS.md`.
5. If any active mission PR is red, treat that red state as the immediate eligible work and continue the **same Issue/branch/PR**.
6. Else if a mission PR is fully green and merge-ready, merge it and verify the change on latest `main`.
7. Else select the highest-value dependency-satisfied open child that has no active owner/branch/PR.
8. Claim it, create/use exactly one issue-scoped branch, and advance it to the nearest meaningful milestone: implementation checkpoint, tests, PR, CI fix, merge, or verified handoff.
9. Never create a duplicate issue/branch/PR merely because another chat/worker ended.
10. After a child merges, update repository-visible handoff/state in its issue and immediately re-evaluate the dependency graph if the current invocation still has execution capacity.
11. Do not publish a release unless the owner explicitly requests release/publication.

### Selection priority

Use this order unless live blockers dictate otherwise:

1. red CI / broken current mission work;
2. green merge-ready current mission PR;
3. W1 identity;
4. W2 data integrity;
5. W3/W5 in parallel when safe;
6. W4;
7. W6;
8. W7;
9. W8;
10. W8B functional screen/journey convergence;
11. W9 final convergence.

---

## 16. How progress is measured

Stored percentages are not authoritative. **GitHub live state is authoritative.**

For reporting, classify each lane as:

- PLANNED;
- CLAIMED;
- IMPLEMENTING;
- PR_OPEN;
- CI_RED;
- CI_GREEN;
- MERGED;
- VERIFIED_ON_MAIN;
- BLOCKED.

A lane counts as complete only when **VERIFIED_ON_MAIN**.

### Mission progress

Operational progress percentage may be shown as:

```
verified child lanes / total implementation lanes
```

But **product completion is binary**. 99% is not “integrated runnable” if one mandatory final gate is open.

---

## 17. Integrated runnable Definition of Done

FOODEX reaches **Merchant Intelligence integrated runnable** only if **all** gates below pass.

### G0 — Repository convergence
- all required W1–W8 and W8B child work merged;
- no unresolved valid mission PR/branch waiting outside main;
- exact final main SHA recorded.

### G1 — Identity & authorization
- one canonical merchant User;
- exact B2B customer/account preserved;
- owner sees owner-authorized Wholesale context;
- Retail staff cannot see owner's personal Wholesale finance;
- support access remains explicit/audited.

### G2 — Data lineage
- Wholesale product → order → receipt → Retail product → inventory lineage is correct;
- conversion factors are honored;
- cross-tenant mappings are impossible;
- receipt is idempotent.

### G3 — Calculation correctness
Regression tests prove:
- available stock;
- reserved stock handling;
- weighted velocity;
- zero/sparse-history behavior;
- days of cover;
- stockout estimate;
- lead-time calculation;
- reorder point;
- safety stock;
- pack/case conversion;
- MOQ/increment rounding;
- slow/overstock behavior;
- precision/rounding.

### G4 — Financial/commercial correctness
- current price/tier is revalidated;
- current availability is revalidated;
- credit/budget constraints use authoritative finance;
- no ledger/invoice double counting;
- no client-trusted financial values.

### G5 — End-to-end merchant journey
Using production-like data:
1. Wholesale purchase exists;
2. purchase is received into the merchant's Retail store;
3. Retail inventory increases;
4. Retail sales reduce/consume inventory;
5. intelligence identifies future risk;
6. recommendation calculates correct quantity/reason;
7. suggested order is repriced/revalidated;
8. merchant edits/reviews;
9. lines reach the existing Wholesale cart;
10. existing checkout remains functional.

No manual DB repair is allowed in the acceptance path.

### G6 — Dashboard UI/UX
- existing route authority preserved;
- compact/data-first FOODEX layout;
- correct tabs/business domains;
- FOODEX green primary actions;
- no raw internal IDs;
- exact-record drill-down;
- AR/RTL + EN/LTR parity;
- desktop/tablet/mobile responsive evidence.

### G7 — Visualization quality
- shared visualization semantics used;
- charts answer explicit business questions;
- no chart spam;
- accessible labels/tooltips;
- stale/empty/error states;
- exact drill-down;
- representative Web + Flutter runtime evidence.

### G8 — Cross-app analytics coverage
Approved Customer/Driver/Van analytics surfaces consume real authoritative data and do not regress route/navigation/application identity.

### G9 — Freshness & failure behavior
- foreground/resume/live refresh behavior defined;
- stale/offline state truthful;
- retries/de-duplication safe;
- server authority wins.

### G10 — Performance
- no N+1 loops across product intelligence;
- bounded/paginated heavy lists;
- large-store fixture tested;
- expensive aggregates indexed/cached/precomputed where justified;
- dashboard does not require an unbounded full-history scan on every request.

### G11 — CI and runtime evidence
- required CI green on exact final SHA;
- tests are not the sole evidence for visual/runtime requirements;
- runtime screenshots/evidence cover representative AR/EN and narrow/wide layouts;
- no known permanent loading/error state.

### G12 — Clean-environment runnable readiness
From a clean supported environment:
- documented setup works;
- migrations succeed;
- required configuration is documented;
- production-like fixture/seed path can exercise the feature where test data is needed;
- app/backend compile/build succeeds for affected components;
- no mock-only dependency;
- no hidden manual database operation.

### G13 — Functional screen & journey convergence
- every required user-facing function is represented in the Screen Coverage Matrix;
- every required matrix row is PASS; PARTIAL / FAIL / UNKNOWN / UNOWNED block completion;
- no backend/API-only user-facing business function remains;
- no hidden/orphaned/placeholder/mock-only/dead-control path remains;
- normal navigation reaches every required function;
- exact-record/context drill-down is used where known;
- actions persist and produce truthful feedback;
- related screens refresh/invalidate so state stays consistent across Dashboard + Customer + Driver + Van;
- critical cross-domain journeys pass runtime E2E on the exact integrated SHA.

Only after G0–G13 pass, including #1129 PASS, may #1122 mark the mission **INTEGRATED_RUNNABLE**.

---

## 18. Release boundary

**Integrated runnable is not the same as published release.**

After #1122 passes:

- record the exact clean `main` SHA;
- verify normal release prerequisites;
- wait for explicit owner instruction to release/publish;
- then use the repository's normal release workflow and release artifact contract.

Do not bump `VERSION` or publish merely because the feature mission is complete.

---

## 19. Required acceptance scenarios

At minimum W9 must exercise:

1. Primary owner with Retail store + linked B2B account sees both contexts.
2. Retail employee sees Retail operations but not owner Wholesale finance.
3. Product with 2 days of cover and 3-day lead time -> reorder-now.
4. Product with 60+ days cover and weak sales -> do-not-reorder/overstock.
5. Retail units mapped to Wholesale case -> recommendation rounds to executable case quantity.
6. Insufficient sales history -> low-confidence state, not false precision.
7. Missing mapping -> explicit mapping action, no guessed source product.
8. Wholesale price/availability changes after recommendation -> cart revalidation uses newest authority.
9. Credit insufficient for full need -> deterministic prioritized subset.
10. Retail sales and Wholesale purchases chart reflects real data and drills down.
11. Stale/offline Dashboard marks recommendations stale.
12. AR/RTL and EN/LTR preserve hierarchy and density.
13. Large product catalog completes within accepted performance budget.
14. Existing non-owner Retail tenant isolation remains unchanged.
15. Every required function has a real reachable screen/action path; no API-only completion.
16. Every actionable KPI/chart/card/list row drills to the exact relevant screen/context where applicable.
17. Cross-screen mutation reconciliation is proven so related surfaces do not disagree after actions.

---

## 20. Non-goals

This mission does not:

- create a second Dashboard;
- create a second Customer App;
- replace the B2B pricing engine;
- replace the ledger/invoice system;
- auto-submit Wholesale orders;
- expose owner finance to ordinary Retail staff;
- use AI as the numeric source of truth;
- publish a release without an explicit release request.

---

## 21. Completion statement template

When #1122 passes, report:

```
FOODEX Merchant Intelligence: INTEGRATED_RUNNABLE
Final main SHA: <sha>
W1-W8 + W8B: VERIFIED_ON_MAIN
G0-G13: PASS
Open mission PRs: 0
Known mission blockers: 0
Runtime evidence: PASS (AR/EN, responsive, production-like data)
Release status: NOT PUBLISHED / READY FOR NORMAL RELEASE GATE
```

Anything less than every mandatory gate passing must be reported as **NOT YET INTEGRATED_RUNNABLE**.

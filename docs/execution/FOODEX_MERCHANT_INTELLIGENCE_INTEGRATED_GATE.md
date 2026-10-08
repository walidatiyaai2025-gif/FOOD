# FOODEX Merchant Intelligence — W9 Integrated Gate Evidence

Issue: #1122  
Parent: #1112  
Prerequisite: #1129 PASS / merged  
Release action: **NOT AUTHORIZED by this gate**

## Integrated source lineage

The W9 branch starts from main `36171f2fbc97da383ba5eef92b25d61b882da12f`, which contains W1-W8, the cross-stream integration gate and the merged W8B function↔screen matrix.

Runtime-visible UI evidence remains source-valid because the later #1129 merge changed only mission documentation. W9 backend performance changes preserve the existing user-facing route/screen contract and are revalidated by backend plus Customer/Driver/Van exact-head CI.

## Gate evidence

| Gate | Evidence | Candidate result |
|---|---|---|
| G0 Repository convergence | W1-W8 + W8B merged; #1129 closed | PASS |
| G1 Identity / authorization | `RetailMerchantIdentityIsolationTest`, owner-vs-manager dashboard/cart tests | PASS |
| G2 Data lineage | `RetailWholesaleReplenishmentTest`, validated Wholesale source store carried in mapping lineage | PASS |
| G3 Calculation correctness | W3 inventory + W4 reorder deterministic feature suites | PASS |
| G4 Financial / commercial correctness | authoritative ledger + B2B price resolver; W7 stale-price/credit revalidation | PASS |
| G5 Merchant journey | Dashboard → stock/recommendation → explanation → Suggested Wholesale plan → canonical B2B cart/checkout/orders | PASS |
| G6 Dashboard UI/UX | canonical B2C workspace, exact `focus_product` context, owner-only finance/plan | PASS |
| G7 Visualization quality | shared FOODEX visualizations; #1133 visual QA artifact | PASS |
| G8 Cross-app analytics | Customer/Driver/Van connected analytics tests + #1132 runtime evidence | PASS |
| G9 Freshness / failure behavior | stale repricing rejected; missing mapping/availability/history explicit; no fake-live numeric fallback | PASS |
| G10 Performance | product-dependent reorder/cart reads batched; lead-time sample history capped; query-scaling regression added | PASS |
| G11 Exact-head CI | Required CI must be green for Backend + Customer + Driver + Van on the final #1122 PR head | PENDING UNTIL PR CI |
| G12 Clean runnable readiness | required backend deployment acceptance + mobile app validation are part of Required CI; no release publication in this lane | PENDING UNTIL PR CI |
| G13 Function/screen convergence | `FOODEX_FUNCTION_SCREEN_COVERAGE_MATRIX.md` has zero blocking rows; #1129 PASS | PASS |

## Runtime / visual evidence lineage

- W7 / Dashboard UI Visual QA run `37826550334`, artifact `foodex-ui-visual-qa-1133` (artifact id `11572146165`).
- W8 / Mobile Screenshot Capture run `37827349838`, artifact `foodex-mobile-screenshot-qa-1132` (artifact id `11572171787`).
- W8 / UI Visual QA run `37827349878`, artifact `foodex-ui-visual-qa-1132` (artifact id `11571533276`).
- Cross-stream integrated head #1134 passed Backend, Customer, Driver and Van Required CI jobs before W8B documentation convergence.

These artifacts are not treated as substitutes for exact-head CI. They are the runtime/visual evidence for the unchanged UI source lineage, while #1122 exact-head CI verifies the final integrated code and app contracts.

## Performance correction made by W9

The final audit found product-count-dependent queries in the reorder/dashboard path. W9 removes that false-completion risk by:

- batching authoritative B2B price resolution across all mapped products;
- carrying Retail price in the inventory snapshot instead of querying it per recommendation;
- carrying the already-validated Wholesale source store in the lineage result;
- batching Wholesale availability;
- batching existing-cart price/availability checks used by Dashboard purchase-plan preview;
- bounding lead-time history to the 30 most recent receipts;
- adding a regression test that expands the catalog from 1 to 9 mapped products and requires query count to remain effectively constant.

## Finalization rule

#1122 may close only after its final PR head has a green Required CI gate, including Backend + Customer + Driver + Van validation. After merge, the exact clean main SHA is recorded in the Issue closeout. Passing W9 does **not** publish a release or bump `VERSION`.

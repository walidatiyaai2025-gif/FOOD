# V2 MOB-C03 / #2732 — Wholesale financial snapshot integrity

**Scope:** Customer app signed B2B credit/available-line/purchasing-power presentation; original Issue #2732, source T115R02/P129, V2 isolated integration target `Foodex_V2` only.

## Canonical contract and defect

`backend/app/Services/B2bAccountLedgerService.php::summary` defines a positive balance as customer credit, a negative balance as customer debt and zero as settled. It computes available credit line as max(credit limit minus receivables, zero), and purchasing power as available line plus positive customer credit. The `/api/v1/b2b/account-summary` endpoint is the server authority; client calculations are DISPLAY VALIDATION ONLY and never authorize checkout, payment, discounts, collections, borrowing, write-offs or financial postings.

The existing account-hub UI trusted `balance_direction` as a label even if it contradicted the signed balance. It also silently defaulted missing or invalid money fields to zero, which could mislead a Wholesale customer about debt and spending power. The change introduces `WholesaleFinancialSummary.tryParse` to reject inconsistent sign/direction, nonfinite/missing monetary fields, negative credit limits, missing currency or broken server-derived credit/purchasing-power relationships. Invalid snapshots now show an AR/EN localized retry state rather than fictional settled/credit/zero figures. No amount is modified, and a new request on Retry uses the existing authenticated API and scope.

## Reuse, authorization and application impacts

- **Customer:** Reuse existing authenticated `/b2b/profile` account hub, same scoped `/api/v1/b2b/account-summary` request. No new screen, route, amount, pricing formula or financial client authority. Active `_B2bMorePage` uses `_FinanceCard`, and the latent account-hub fallback uses the same validated data contract. Existing shared widgets and locale keys preserved, RTL/LTR covered.
- **Backend/Dashboard:** Unchanged. The authoritative B2B ledger service, credit/discount/partial collection/approval and their RBAC/SoD enforcement remain server-side. No new privileged endpoint or client posting.
- **Driver:** No Driver API, collection UI, assignment or runtime contract change. B2C=DRIVER only preserved.
- **Van:** No Van API, custody, route/assignment/offline/settlement contract change. B2B=VAN only preserved.

## Verification and remaining source coverage

`test/v2_wholesale_financial_summary_test.dart` verifies signed debt/credit/settled, canonical purchasing power, missing monetary fields, NaN, contradictory direction and cross-field drift. `test/b2b_journey_test.dart` adds AR/EN/RTL-LTR UI regression that invalid data does not become a misleading zero-balance screen, and corrects one synthetic fixture to the real negative-debt convention. Existing B2B shopping/checkout/credit/price, invoice/partial payment and statement tests remain applicable. Required exact-head Customer Flutter and repository/isolated backend gates must pass.

**Not yet full MOB-C03 acceptance:** The larger source still requires an integrated, tested customer checkout/pricing/discount/partial-collection matrix with authorized B2B store/session/finance state, idempotent payment and account statement readback, AR/EN and failure modes. Do not close #2732 or merge this bounded change as if that entire source-wide acceptance were complete until those tests and any missing runtime fixes are provided. Manual staging/device UAT is deferred to master #2503 only; code and CI cannot be waived. No `main`, production deployment, releases, tags or APK publication.

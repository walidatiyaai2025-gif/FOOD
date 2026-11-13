# V2 #2559 / P229 — Profitability Rules: source-to-runtime authority audit

**Status:** BLOCKED ON CANONICAL RULE / COST AUTHORITY. This is a design and runtime gap report, **not** implementation, sign-off, source acceptance or a release. **Target:** `Foodex_V2` only. Parent #2503. P229 title: `قواعد الربحية` in Catalog & Pricing.

## Preflight and reuse decision

- Existing canonical Catalogue & Pricing entry point: `AdminNavigation` → `admin.commercial.sales-control` → `CommercialDashboardController::salesControl` (GET) and `saveSalesControl` (PUT). Existing product policy covers sales status, unit/break-pack, quotas, availability windows and customer/channel targeting; **no profit-floor or profit-rule mutation** is defined there.
- Existing pricing is authoritative in `CommerceQuoteService`, `CommercialPricingResolver` and `B2bPriceResolver`; `CommercialTruthRegistry` explicitly identifies the pricing write authority. UI-supplied prices are never authoritative.
- Existing `docs/architecture/COMMERCIAL_PROFITABILITY_READ_MODEL.md` defines an **after-event, rebuildable BI projection**, not the source commercial/finance ledger or a price-approval policy. Its cost is provable only from an applicable WMS standard-cost valuation policy at event time. Missing/ambiguous/historically inapplicable cost **must remain NULL**, not zero or inferred. This report must not be converted into an operational pricing/margin floor.
- Existing BI profitability analytics / reports are separate from **P229 profitability policy**; pointing P229 to a chart alone would be a misleading dead workflow. Related V2 P315 (#2645) is analytics, not profitability rule management.
- Placement: **Reuse → Extend** the existing authorized Commercial Sales Control as a contextual tab / form only **after** a real write/read/approval guard is available; no new Sidebar route, independent visibility policy or second valuation ledger. No production navigation change is made by this audit.

## Required source contracts before safe financial code

1. **Rule schema and responsibility:** Specify the supported margin policy (e.g. floor percentage or absolute contribution), actor/role, legal entity, channel, store, product or selling unit, effective interval, currency and inheritance/precedence. P229's navigation title alone does **not** authorize choosing arbitrary thresholds or blanket defaults.
2. **Valuation authority:** For every affected quote/discount/order, establish **current** authorized store+SKU+selling-unit cost provenance from the WMS valuation owner. Define semantics for mixed vendor/FOODEX legal-title stock, unknown legacy stock, cross-warehouse cases and unavailable cost. Dependency: #2844 already documents missing legal stock-owner dimension; never label unowned stock as FOODEX.
3. **SoD and adjustments:** Specify which user may propose, approve, activate, override and audit a rule; how existing discounts/promotions/coupons/price tiers and customer credit interact with the floor; and whether below-floor quotes are rejected or held for approval. Preserve other finance controls; coordinate #2853.
4. **Data integrity:** Version approved rules; use decimal money, currency-safe comparison, timezone-safe effective dates, immutable quote/order snapshots, idempotent mutations and tested rollback/recovery. Preserve historical orders.
5. **Failure semantics:** Missing/ambiguous valuation must produce explicit **UNVALUED / approval-required** state, not a fabricated positive/negative margin. Protect direct HTTP actions with role, company, store, channel, module entitlement/readiness and 403/404 as applicable; no UI-only guard.
6. **Bilingual flow:** Existing Commercial Sales Control is the canonical parent. A contextual Profitability Rules tab must be accessible via the existing authorized Catalog & Pricing navigation, work on 320–1280px, RTL/LTR, AR/EN, keyboard/a11y and all failure states. No raw IDs or JSON for business users.

## Backend / Dashboard / Mobile impact classification

| Surface | Assessment | Required evidence |
| --- | --- | --- |
| Backend | **Affected** once a profitability policy is approved: canonical price/discount guard, audited rule lifecycle and source cost authority. | Laravel positive, denied/direct HTTP, multi-role, cross-store, B2B/B2C, module-off, unvalued cost, currency and SoD tests. |
| Dashboard | **Affected** once rule lifecycle exists: scoped controls on the existing Sales Control page/tab. | Real AR/EN UI, responsive/accessibility, readiness and authorization tests. |
| Customer | **Conditionally affected** if pricing, discount or quote eligibility changes; not safe to assert no impact. | Linked V2 Customer issue, backend/mobile contract and offer/checkout regression before integration. |
| Driver | **Not directly affected by a B2B-only candidate policy**, but B2C scope undecided; do not assume no impact if rules include Retail. | Explicit B2C/Driver scope proof and regression before merge. |
| Van | **Conditionally affected** for B2B quote/order/direct-sale eligibility and approval workflow. | Linked V2 Van issue and offline/replay price authority tests if policy touches B2B. |

## Acceptance and handoff

- This document records **source and safety blockers only**. No rule entity, database migration, quotation change, unauthorized UI action or artificial approval flow has been introduced.
- Before further code: obtain the source-authoritative profitability policy semantics and the compatible valuation/legal-ownership solution. Recheck active PR/file owners and continue **the same** #2559 branch and PR.
- Implement exact owned policy + existing-page form + server enforcement + fully scoped security/failure tests; classify/implement each actual app impact; pass exact-head Repository Policy, V2 Isolation and Required CI (including child jobs). Merge only into `Foodex_V2`; close #2559 only after **real** source requirements, implementation, app parity and CI are complete.
- No `main` integration, release, deployment, tags, production data access or test of a live financial transaction is authorized.

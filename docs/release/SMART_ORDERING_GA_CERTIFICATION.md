# Smart Ordering Program GA Certification — #1456

This document is the integration-captain release evidence for parent #1445 and closure issue #1456. It certifies the repository state promoted from `feature/smart-ordering-program`; it does not claim that a production deployment occurred.

## Integrated delivery ledger

| Capability | Issue | Integration PR | Primary acceptance |
| --- | ---: | ---: | --- |
| Tenant/subdomain runtime, entitlements, RBAC, publish | #1446 | #1460 | `SmartOrderingFoundationTest` |
| Menu builder, modifiers, availability | #1447 | #1465 | `SmartOrderingMenuDomainTest` |
| Five premium storefront templates | #1448 | #1457 | `SmartOrderingStorefrontTest` |
| Cart, checkout, fulfillment and lifecycle | #1449 | #1458 | `SmartOrderingCoreTest` |
| Dynamic/table/campaign QR attribution | #1450 | #1459 | `SmartOrderingQrTest` |
| WhatsApp CRM bridge and lifecycle messages | #1451 | #1467 | `SmartOrderingWhatsAppBridgeTest` + core regression |
| Customer CRM, loyalty, reviews and recovery | #1452 | #1466 | `SmartOrderingCustomerGrowthTest` |
| Offers, coupons, upsell and personalization | #1453 | #1471 | merchandising/core acceptance |
| Analytics, funnel and attribution | #1454 | #1472 | `SmartOrderingAnalyticsTest` |
| FOODEX supply intelligence | #1455 | #1470 | `SmartOrderingSupplyIntelligenceTest` |
| Latest main WhatsApp/campaign/inbox convergence | #1456 | #1474 | full required CI on integration sync |

## Program E2E acceptance matrix

The program gate is satisfied by the Smart Ordering feature suites plus the repository Required CI Gate. The matrix below is intentionally cross-domain so final promotion is not based on one child lane in isolation.

- QR/menu/cart/pickup/order/WhatsApp: QR attribution + menu + core checkout + WhatsApp bridge suites.
- Table QR/dine-in: QR and core fulfillment suites.
- Delivery zones, fees, minimums and scheduled orders: core checkout suite.
- Sold-out/modifier/price revalidation: menu/core authoritative-pricing suites.
- Theme switch and all five premium templates in Arabic RTL and English LTR: storefront suite.
- WhatsApp provider failure: core/WhatsApp bridge acceptance confirms a valid FOODEX order is not rolled back by provider failure.
- Repeat order/customer growth: customer-growth suite; final pricing remains backend-authoritative.
- Offer/coupon/upsell conversion: merchandising/core acceptance.
- QR/campaign/source attribution and analytics funnel: QR + analytics suites.
- Loyalty/reviews/service recovery: customer-growth suite.
- Supply mapping -> consumption/reorder -> FOODEX assisted draft: supply-intelligence suite.

Canonical program command for focused local/staging validation:

```bash
cd backend
php artisan test \
  tests/Feature/SmartOrderingFoundationTest.php \
  tests/Feature/SmartOrderingMenuDomainTest.php \
  tests/Feature/SmartOrderingStorefrontTest.php \
  tests/Feature/SmartOrderingCoreTest.php \
  tests/Feature/SmartOrderingQrTest.php \
  tests/Feature/SmartOrderingWhatsAppBridgeTest.php \
  tests/Feature/SmartOrderingCustomerGrowthTest.php \
  tests/Feature/SmartOrderingAnalyticsTest.php \
  tests/Feature/SmartOrderingSupplyIntelligenceTest.php
```

The normal repository Required CI Gate remains authoritative because it also executes backend static analysis, migrations/deployment acceptance, localization policy and existing FOODEX regressions outside Smart Ordering.

## Security certification

Release must remain blocked if any item below fails.

- Tenant/site/branch scoping is server-side; host/subdomain input cannot directly choose another tenant.
- Public surfaces use opaque/public identifiers where defined; internal numeric IDs are not accepted as cross-tenant selectors.
- Pricing, discounts, tax/fees, availability and checkout totals are recalculated by backend services.
- Admin mutations remain permission/CSRF protected and audited.
- Public/cart/checkout/QR flows preserve validation and rate-limit middleware defined by their routes.
- Idempotency protects checkout replay and rejects reuse with a different request payload.
- QR/table context is validated rather than trusted from client-visible values.
- Existing WhatsApp emergency-stop/runtime authority is reused; Smart Ordering does not create a second WhatsApp control plane.
- No provider tokens, secrets or customer PII may be written to analytics dimensions or browser-visible configuration.
- Any cross-tenant authorization failure is a P0 and blocks promotion.

## Performance and bounded-data certification

- Storefront performance budgets are centralized in `config/smart_ordering_storefront.php`; theme/static assets must stay inside those budgets.
- Menu/search/storefront paths use published-revision and availability services rather than controller-side N+1 data assembly.
- Admin analytics and supply screens use bounded datasets/authoritative selectors; large result surfaces must retain explicit pagination or bounded-data contracts.
- Queue/provider work must not convert a successful canonical order transaction into a provider-dependent transaction.
- Cache changes must preserve publish/revision invalidation semantics.
- The Required CI Gate must be green after the final integration head, not only on child heads.

Operational alert starting points for pilot/GA:
- cross-tenant authorization anomaly: **any occurrence -> stop**;
- checkout HTTP 5xx/error rate: **>2% for 5 minutes -> investigate / consider EMERGENCY_STOP**;
- WhatsApp transactional send failure: **>5% for 5 minutes -> keep FOODEX orders valid, disable WhatsApp module if sustained**;
- storefront/menu p95 server response: **>2 seconds for 15 minutes -> investigate before wider rollout**;
- queue retry/dead-letter growth: **sustained increase for 10 minutes -> pause rollout**;
- order/quote mismatch or idempotency conflict outside legitimate replay: **any confirmed defect -> stop**.

## Migration and rollback notes

Smart Ordering migrations introduced by the child lanes, in program order:

1. `2026_10_15_030000_create_smart_ordering_foundation.php`
2. `2026_10_15_031000_create_smart_ordering_menu_domain.php`
3. `2026_10_15_041500_extend_smart_ordering_checkout_core.php`
4. `2026_10_15_050000_create_smart_ordering_customer_growth.php`
5. `2026_10_15_053000_create_smart_ordering_whatsapp_bridge.php`
6. `2026_10_15_060000_create_smart_ordering_qr_attribution.php`
7. `2026_10_15_063000_create_smart_ordering_analytics.php`
8. `2026_10_15_070000_create_smart_ordering_merchandising.php`
9. `2026_10_15_070000_create_smart_ordering_supply_intelligence.php`

Deployment sequence:
1. capture the normal FOODEX application/database backup;
2. deploy code with Smart Ordering disabled by default;
3. run normal migration workflow and health checks;
4. exercise INTERNAL_TEST, then PILOT for selected tenant(s);
5. promote to ENABLED only after metrics and acceptance remain healthy.

Fast operational rollback is **feature-state rollback first**: set affected site/module to `EMERGENCY_STOP` or `DISABLED` and preserve canonical FOODEX orders. Database rollback is a release-owner operation and must use the normal FOODEX backup/migration procedure; never destructively roll back live Smart Ordering data merely to disable traffic.

## Rollout / pilot acceptance

The runtime states are:
`DISABLED -> INTERNAL_TEST -> PILOT -> ENABLED`, with `EMERGENCY_STOP` available at any point.

Acceptance rules:
- DISABLED is the safe default for restaurants that are not explicitly enabled.
- INTERNAL_TEST and PILOT access are gated and audited by the runtime policy.
- Pilot acceptance includes Arabic/English storefront, one flow for each enabled fulfillment mode, one WhatsApp status flow, one repeat/offer flow, analytics attribution and one supply draft when the supply entitlement is enabled.
- Widening the pilot is blocked by P0/P1 defects, tenant leakage, price/order mismatch, sustained checkout/provider error thresholds or failed rollback/emergency-stop exercise.
- Repository certification of INTERNAL_TEST/PILOT runtime behavior is automated; this document does not fabricate production restaurant acceptance or deployment timestamps.

## Emergency-stop rehearsal

1. Begin with a tenant in INTERNAL_TEST/PILOT and confirm the public flow is available to the authorized pilot context.
2. Switch the runtime to `EMERGENCY_STOP`.
3. Confirm new Smart Ordering traffic fails closed while existing canonical FOODEX order records remain intact.
4. Confirm WhatsApp/provider failure does not delete or roll back an already-created FOODEX order.
5. Restore only after the incident condition is understood; all state changes remain auditable.

## GA promotion gate

All of the following are required on the exact final integration/promotion head:

- all child issues #1446-#1455 integrated;
- Repository Policy green;
- Arabic/English localization quality green;
- backend fast-fail/static analysis green;
- backend test suite green;
- MySQL/Redis deployment acceptance green;
- required dashboard visual evidence green when applicable;
- no unresolved P0/P1 Smart Ordering defect;
- emergency-stop/rollback procedure documented and runtime behavior covered;
- final promotion PR targets `main` and references #1445 and #1456.

## Known limitations / explicit boundaries

- WhatsApp delivery depends on the configured provider; provider failure is handled as a communication failure, not as authority to roll back a valid FOODEX order.
- Supply recommendations are decision support; authoritative stock evidence is distinguished from consumption-trend estimates.
- Production/staging backup IDs, real provider credentials and business-owner pilot timestamps are deployment evidence, not repository data, and must not be invented in Git.
- GA promotion of code does not automatically enable Smart Ordering for every restaurant; entitlement/runtime controls remain authoritative.

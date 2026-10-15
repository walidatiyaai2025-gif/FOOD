# Smart Ordering GA Certification — #1456

This file is the release record for the Smart Ordering program parent #1445 and integration issue #1456.

## Integrated scope

The GA candidate includes the completed lanes #1446 through #1455 on `feature/smart-ordering-program`:

- tenant/subdomain runtime, entitlements, RBAC and publish foundation
- restaurant menu builder and availability
- five premium storefront templates with AR/EN and RTL/LTR
- cart, authoritative checkout, fulfillment and order lifecycle
- dynamic QR/table/campaign attribution
- WhatsApp CRM bridge and transactional lifecycle notifications
- restaurant customer CRM, repeat order, loyalty, reviews and service recovery
- offers, coupons, combos, upsell/cross-sell and personalization
- analytics, funnel, attribution and abandoned-cart reporting
- FOODEX supply mapping, estimated consumption and reorder-draft intelligence

## Automated certification

Run:

```bash
bash scripts/smart-ordering-ga-acceptance.sh
```

The gate performs a fresh SQLite migration and executes the complete Smart Ordering feature regression set plus the program-level composition contract. It covers tenant isolation, runtime/default-off policy, published menu behavior, five-template rendering contracts, authoritative pricing, delivery/table/checkout rules, lifecycle/idempotency, QR forgery/attribution, WhatsApp failure isolation, CRM/loyalty/reviews, analytics and supply intelligence.

The dedicated GitHub Actions workflow `.github/workflows/smart-ordering-ga-certification.yml` executes the same gate for integration and main promotion PRs.

## Release invariants

1. Smart Ordering remains disabled by default for fresh/existing tenants until explicitly enabled.
2. Canonical orders and money totals remain server authoritative.
3. WhatsApp is downstream communication, never the order persistence authority.
4. Public identifiers are opaque/public-safe and tenant/branch scope is resolved server-side.
5. Emergency stop remains available through the centralized Smart Ordering runtime.
6. Rollback is application-code rollback plus runtime `EMERGENCY_STOP`/master disable; published menu revisions remain immutable.
7. No child lane is promoted directly to `main`; only the certified integration candidate is promoted.

## Rollout sequence

1. Deploy candidate with Smart Ordering disabled by default.
2. Enable `INTERNAL_TEST` only for an allowlisted restaurant and validate QR → menu → cart → order → WhatsApp.
3. Promote selected restaurant(s) to `PILOT`; monitor conversion, checkout failures, order mismatch, notification/provider failures and analytics ingestion.
4. Verify master disable and `EMERGENCY_STOP` fail closed without deleting canonical orders.
5. Move approved restaurants to `ENABLED`; do not bulk-enable tenants automatically.

## Rollback

- Set affected restaurant/site to `EMERGENCY_STOP` or disable the Smart Ordering master switch first.
- Roll back the application release if required.
- Do not delete Smart Ordering order contexts, lifecycle events, QR attribution, loyalty ledgers or analytics events during rollback.
- Re-run the GA acceptance gate before re-enabling.

## Known operational constraint

Custom domains require the existing verification hook and should remain entitlement-controlled until DNS/TLS verification is complete. Provider-dependent WhatsApp delivery can fail independently; such failures must not roll back a valid FOODEX order.

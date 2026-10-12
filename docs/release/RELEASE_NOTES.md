# FOODEX 1.0.75 Release Notes

Release promotion from the authoritative post-1.0.73 `main` lineage through Customer VNext final certification PR #1353. Version 1.0.74 is intentionally skipped; 1.0.75 is the next authoritative synchronized release.

## Release identity

- Dashboard / repository release: `1.0.75`
- Customer source identity: `1.0.75+75`
- Driver source identity: `1.0.75+75`
- Van source identity: `1.0.75+75`
- Dashboard Update ZIP: independently deployable over existing FOODEX 1.0.73
- Android artifacts: synchronized Customer, Driver and Van release APKs are built from the exact merged `main` release commit
- Production API: `https://foodex.50sols.com`
- Forbidden legacy API: `https://vanfoodex.50sols.com`

## Included changes

- Promote all authoritative `main` changes after FOODEX 1.0.73 through PR #1353 into FOODEX 1.0.75.
- Ship the complete Customer VNext rollout: shared responsive foundation, Shopping Home, catalog/search/product detail, cart, checkout, order-success confirmation, orders/detail/tracking lifecycle, account and secondary surfaces, and final integrated certification.
- Preserve authoritative Wholesale/Retail store, channel, cart, checkout, order and account isolation while keeping Arabic RTL and English LTR behavior covered by the VNext acceptance suite.
- Include the secure real-data Customer VNext fixture/preflight baseline and final screenshot/route/state evidence used by the integrated release gate.
- Include post-1.0.73 Dashboard/backend improvements: Administration and Settings center redesign, localized Assistant conversation basics, Flash Offers repair with automated product SKU generation, protected reset/reseed controls, production launch preflight/gate, and 14-inch order-management optimization.
- Synchronize Customer, Driver and Van source identities at `1.0.75+75`. Version `1.0.74` is intentionally not published.

## Validation contract

- Required CI, Repository Policy, Store Submission Readiness, Update Bundle validation and Platform Customer Commerce E2E must pass on the exact release head.
- Customer VNext final certification, CJ-V2, C13, commerce isolation and Customer Android release validation must remain green.
- The generated `FOODEX-Update.zip` must target `1.0.75` and the canonical Release Factory must publish `FOODEX-Customer-1.0.75.apk` from the exact merged `main` release commit.

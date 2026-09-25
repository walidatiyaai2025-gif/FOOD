# Product E2E Acceptance

PC-18 validates the approved product surfaces as one acceptance gate.

The suite executes the backend feature tests plus the Customer and Driver Flutter test suites. Existing feature coverage includes B2C guest/cart/checkout/order/profile flows, scoped B2C administration, B2B account/pricing/finance/reporting flows, B2B administration, driver assignment lifecycle and strict B2C/B2B driver separation, authorization/store isolation, and Arabic RTL / English LTR workspace smoke coverage.

Run from the repository root:

```bash
./scripts/run-product-acceptance.sh
```

Acceptance is complete only when the repository policy and every applicable required CI validation are green. No production endpoint is mocked or invented by this runner.

# FOODEX 1.0.118 — Synchronized Release Notes

## Identity

- Dashboard / repository: `1.0.118`.
- Customer App: `1.0.118+118`.
- Driver App: `1.0.118+118`.
- Van App: `1.0.118+118`.

## Changes

- Customer order timeline de-duplicates repeated canonical delivery milestones while preserving chronological progression.
- Customer Order Details displays the authoritative unpaid/partially paid/fully paid state, paid amount and outstanding amount with distinct colors.
- Driver App wallet adds a collected amount card linking to driver-scoped order collection details (posted receipts only), with individual collected/remaining balances.
- Dashboard Order Management shows payment state, paid and remaining amounts; Drivers > Orders reports the driver-specific collected amount, per-order remaining balance, and a currency-separated total.
- Tenant/driver isolation, ledger consistency and regression tests cover the new report and UI paths.

## Release quality gate

Do not represent 1.0.118 as published until this change is merged to `main`, required CI succeeds on the exact final SHA, and the canonical `.github/workflows/release-factory.yml` creates and validates Dashboard Update + Laravel Setup + Customer/Driver/Van APKs with matching provenance and manifests.


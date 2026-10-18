# B2C Retail / Restaurant final convergence — #2175

Certification branch: `test/2175-final-certification`

Base main at certification start: `922855bb45c7da95e776248912d1a05e75e5a54b`.

## Merged implementation evidence

- #2168 — authoritative `RETAIL_STORE` / `RESTAURANT` profile contract.
- #2169 — split Add Store provisioning flow.
- #2170 — business-profile-aware built-in RBAC.
- #2171 — isolated Retail and Restaurant workspaces and fail-closed direct routes.
- #2172 — dedicated Restaurant Wholesale Purchases reusing canonical B2B infrastructure.
- #2173 — profile-aware post-login routing.
- #2174 — Restaurant task-first density and responsive convergence.

## Final certification coverage

`B2cBusinessProfileFinalCertificationTest` verifies the merged architecture rather than re-implementing it:

- authoritative profile enum/service and legacy backfill evidence;
- profile-required provisioning;
- profile-aware RBAC configuration and validation;
- Retail/Restaurant route isolation;
- Restaurant-only access enforcement;
- profile-aware login landing;
- canonical Restaurant Wholesale Purchases route in the capability inventory;
- Restaurant task-first responsive breakpoints;
- presence of the child regression suites that own the detailed behavior.

## Merge gate

Do not close #2175 until the certification PR is mergeable and both required workflows are green on its exact head:

- FOODEX Required CI Gate
- Repository Policy

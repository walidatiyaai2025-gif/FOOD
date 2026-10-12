# FOODEX Production Launch Gate evidence contract

Issue: #1332  
Parent: #1323

The production launch gate is fail-closed. A missing, stale, unknown or failed required check is a blocker; there is no warning-only bypass.

Required check IDs:

- schema_migrations
- environment_configuration
- release_lineage
- official_seed
- product_image_quality
- clean_production_data
- api_customer_contract
- customer_ar_en_registry
- customer_e2e
- provider_readiness
- mobile_artifacts
- backup_rollback
- exact_head_required_ci

Evidence-producing lanes write server-side attestations under `storage/app/private/launch-gate/`. Those files are deployment artifacts, not repository fixtures. Each JSON attestation must have `status: "passed"` and a 40-64 character lowercase hexadecimal `source_sha`. The SHA must equal the production source commit recorded by `Release/BUILD_INFO.json`; evidence from another commit is stale and blocks launch.

The environment check is fail-closed and requires production mode, an application key, debug disabled, and a configured database connection. The clean-data scan blocks known demo/test user, customer and transaction markers. The gate never reads or displays provider secrets. Provider readiness is a boolean operational attestation. Exact-head Required CI is an evidence artifact from the release pipeline and must identify the exact promoted source SHA.

Owner approval requires SUPER_ADMIN and the exact typed phrase `LAUNCH FOODEX PRODUCTION`. Approval is audited. The Dashboard promotion wiring must call the same fail-closed service; it must not reimplement or weaken these checks.

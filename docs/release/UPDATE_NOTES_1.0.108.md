# FOODEX Dashboard Update 1.0.108

Date: 2026-10-24

## Scope

Dashboard/backend only. Customer, Driver and Van Android source identities remain unchanged and are not rebuilt.

## Included fixes

- Carries the complete cumulative FOODEX Dashboard 1.0.107 lineage.
- Ports the completed Assistant provider-test repair from #2399 / PR #2400 onto the 1.0.107 single-request provider-test architecture.
- Uses purpose-scoped named rate limiters for **Test saved connection** and **Test agent capabilities**, preventing unrelated authenticated Dashboard numeric-throttle traffic from exhausting their buckets.
- Preserves the existing ceilings: 10 saved-connection tests per minute and 5 capability tests per minute, including HTTP 429 and Retry-After behavior.
- Raises provider-test output allowance from the internal 128-token ceiling to a bounded maximum of 512 tokens, never above the configured provider output limit.
- Keeps Qwen3 `reasoning_effort: none`, the one-request saved-connection test, the one-request forced-tool capability test, no immediate retry on provider HTTP 429, and AI/Database response-source labeling.
- RBAC, tenant/store/channel scope, read-only Assistant tools, secret handling and no-chain-of-thought exposure remain unchanged.

## Deployment

Install through Dashboard > System Update using the generated `FOODEX-Update.zip` and matching manifest/checksum from the canonical `release/1.0.108-update` branch after exact-head CI passes. The package is cumulative from the supported updater minimum and does not require a mobile application rebuild.

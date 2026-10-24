# FOODEX Dashboard Update 1.0.107

Date: 2026-10-24

## Scope

Dashboard/backend only. Customer, Driver and Van Android source identities remain unchanged and are not rebuilt.

## Included fixes

- Qwen3 requests use OpenAI-compatible `reasoning_effort: none` for FOODEX low-latency provider paths.
- Assistant Settings now separates a one-request **Test saved connection** check from a one-request **Test agent capabilities** forced-tool contract. The previous three-request burst is removed, preventing the Dashboard test action from amplifying HTTP 429 Too Many Requests.
- HTTP 429 is no longer immediately retried by the provider resilience loop.
- Assistant replies now expose a safe response-source marker in the API and UI: AI for model-only replies, Database for FOODEX tool/evidence-backed replies.
- Arabic and English response-source labels are included with no chain-of-thought exposure.
- RBAC, read-only enforcement, tenant/store/channel scope and authoritative FOODEX data boundaries are unchanged.

## Deployment

Install through Dashboard > System Update using the generated `FOODEX-Update.zip` and matching manifest/checksum from the canonical `release/1.0.107-update` branch. The update is cumulative from the supported updater minimum and is based on the completed 1.0.106 Dashboard lineage.

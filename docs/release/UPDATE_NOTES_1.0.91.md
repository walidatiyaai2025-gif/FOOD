# FOODEX 1.0.91 Release Notes

Status: cumulative Dashboard hotfix from the current authoritative main lineage.

## Release identity

- Dashboard / repository: `1.0.91`
- Customer: `1.0.91+91`
- Driver: `1.0.91+91`
- Van: `1.0.91+91`

## Included

- Reliable Ollama/Qwen Assistant routing when model output contains leading Qwen thinking wrappers or fenced JSON.
- Strict saved-provider compatibility probe requiring the exact `FOODEX_OK` sentinel.
- Bounded provider token budgets adjusted to leave room for the final routing result.
- Existing Assistant RBAC, read-only tool allowlist, store/channel authority and deterministic fallback are preserved.
- All previously merged FOODEX 1.0.90 functionality from current main.

## Dashboard update

- Target version: `1.0.91`.
- Cumulative minimum/current-version boundary is resolved by the canonical update workflow.
- Full redeploy required: **No**.
- Package, manifest, checksum and file list are generated automatically and must not be hand-edited.

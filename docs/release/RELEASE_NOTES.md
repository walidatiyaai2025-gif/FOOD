# FOODEX 1.0.41 Release Notes

Status: synchronized reliability distribution containing the repository fixes merged after the already-distributed 1.0.40. This release is the first promotion governed by the immutable main release registry.

## Release identity

- Dashboard: `1.0.41`
- Customer app: `1.0.41+41`
- Driver app: `1.0.41+41`
- Customer runtime/footer identity: `1.0.41`
- Driver runtime/footer identity: `1.0.41`
- Driver diagnostics, heartbeat telemetry and version-policy current identity: `1.0.41`
- Driver diagnostics build identity: `41`

## Deployable delta since distributed 1.0.40

### Preview runtime distribution
- Add repeatable production build/distribution for the real Customer and Driver Flutter Web preview runtimes.
- Keep runtime artifacts version-identifiable and validate required deployed asset/runtime contracts without introducing a fake Dashboard renderer.

### Driver reliability and observability
- Record safe Driver version-policy failure categories for timeout, socket/network, HTTP, invalid JSON, invalid policy and client errors.
- Preserve retry/fail-closed behavior while adding safe attempt, version/build, elapsed-time and correlation metadata.
- Harden Dashboard live Driver tracking against missing Leaflet/runtime assets, initialization failures and feed failures; successful empty feeds now render an explicit zero-driver state and update timestamp.

### Customer reliability
- Make protected B2B authentication return domain-aware and preserve exact safe internal B2B destinations/query parameters after login.
- Remove raw internal route strings from normal Customer UI and add retry/back recovery plus safe support references for product/runtime failures.
- Preserve diagnostics privacy: no credentials, request/response bodies, customer PII or precise coordinates.

### Mobile policy readiness
- Distinguish informational Mobile Settings from authoritative AppVersion policy.
- Surface Android/iOS rollout readiness and exact safe Driver location-enforcement blockers in the Dashboard.
- Preserve the safeguard that Driver location enforcement cannot be enabled before both platforms satisfy governed rollout requirements.

## Release governance
- Register 1.0.41 on `main` in `docs/release/RELEASE_REGISTRY.json`.
- Publish the generated cumulative `FOODEX-Update.zip` plus manifest/checksum/file list under `Release/Updates`.
- The registered package SHA-256 must match the package bytes, manifest and checksum file before merge.
- 1.0.40 remains immutable and may not be reused for this payload.

## Explicit non-activation statement

This release does **not**:
- change production minimum-supported AppVersion rows;
- enable Driver fresh-location enforcement;
- change the existing production Driver enforcement state;
- fabricate production deployment/device evidence.

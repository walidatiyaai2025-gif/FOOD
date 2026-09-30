# FOODEX 1.0.39 Release Notes

Status: synchronized trial-distribution release for the merged runtime baseline after the Driver live-tracking chain. Publishing this release does not activate production Driver minimum-version enforcement or fresh-location enforcement.

## Release identity

- Dashboard: `1.0.39`
- Customer app: `1.0.39+39`
- Driver app: `1.0.39+39`
- Customer runtime/footer identity: `1.0.39`
- Driver runtime/footer identity: `1.0.39`
- Driver diagnostics, heartbeat telemetry and version-policy current identity: `1.0.39`
- Driver diagnostics build identity: `39`

## Deployable delta since 1.0.38

### Customer preview and storefront revision runtime
- Adds the real Customer Flutter Web preview host/runtime and authenticated Customer read bridge.
- Resolves guest and authenticated Draft/Published storefront revisions using scoped preview sessions.
- Adds scoped live preview invalidation and parity coverage for Retail/Wholesale directionality and shared runtime behavior.
- Adds sanitized Preview Inspector export without production credentials or sensitive query values.
- Includes the revisioned Draft/Published storefront backend foundation required by the preview runtime. Storefront editor activation remains owned by its separate task and is not fabricated by this release branch.

### Driver live tracking and rollout controls
- Adds Dashboard live Driver tracking with a dedicated page, sidebar entry and compact B2B/B2C map cards.
- Adds the Driver app minimum-version policy gate and current-version reporting.
- Adds server-side fresh-location enforcement capability and stable recovery semantics.
- Removes the unaudited environment-variable enforcement-enable fallback; no persisted environment-scoped setting means enforcement is OFF.
- Adds active-delivery background/foreground Driver tracking lifecycle with Android foreground-location service declarations and iOS location background mode.
- Keeps broad Android `ACCESS_BACKGROUND_LOCATION` absent and does not request iOS Always permission unless a future supported path requires it.
- Stops tracking on logout/session invalidation or permission/service loss and avoids high-frequency background tracking without an active delivery.
- Adds the real Driver Flutter Web preview bridge; preview remains exempt from native/background tracking.

### Validation and distribution
- Customer and Driver Android release builds and iOS no-codesign builds remain required CI gates.
- Driver native validation checks location permissions/service declarations and iOS background-mode contract.
- Customer Preview Web and Driver Preview Web release builds are validated in CI.
- Trial Distribution generates synchronized Customer APK, Driver APK, Laravel setup/update artifacts and `BUILD_INFO.json` from the final `main` source commit.

## Explicit non-activation statement

This source release does **not**:
- change production minimum-supported AppVersion rows;
- enable Driver fresh-location enforcement;
- activate Driver location enforcement through configuration or database mutation;
- claim production heartbeat evidence;
- mark parent rollout #512 operationally complete.

Production heartbeat evidence, minimum-version activation and enforcement activation remain separate governed operational steps after published-artifact verification.

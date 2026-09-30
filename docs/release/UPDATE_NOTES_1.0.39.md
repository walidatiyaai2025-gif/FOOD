# FOODEX 1.0.39 Distribution Notes

This release synchronizes Dashboard, Customer and Driver artifacts on `1.0.39` / mobile build `+39`.

The deployable delta since the 1.0.38 identity includes the real Customer and Driver Flutter Web preview bridges, Draft/Published preview resolution and invalidation, sanitized preview diagnostics, Dashboard live Driver tracking, Driver minimum-version policy checks, fresh-location enforcement capability, the audited OFF-by-default enforcement path, and active-delivery Driver background tracking with Android/iOS native validation.

Publishing 1.0.39 is distribution only. It does not modify production AppVersion rows, does not change the production minimum-supported Driver version, and does not activate Driver location enforcement. Production heartbeat evidence and activation remain separate operational gates.


## Distribution artifact identity

The GitHub trial-distribution publisher must build both Android APKs as `1.0.39+39` and publish `BUILD_INFO.json` beside them. The manifest records the source commit, application IDs, mobile build number, byte size, SHA-256 digest, Firebase configuration state, and Android signing state.

The current GitHub tester APK path uses the Flutter template debug key and is explicitly marked `production_store_ready: false`. It is suitable for controlled rollout/heartbeat acceptance, but it must not be represented as a production-store-signed artifact. Production AppVersion changes and fresh-location enforcement activation remain separate #512 operational gates.

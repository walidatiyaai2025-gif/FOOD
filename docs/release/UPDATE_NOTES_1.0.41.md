# FOODEX 1.0.41 Distribution Notes

This release publishes the reliability work merged after the already-distributed `1.0.40` under the new immutable identity `1.0.41` / mobile build `+41`.

Included reliability work:
- production distribution for the real Customer/Driver Flutter Web preview runtimes;
- Driver version-policy failure observability and retry diagnostics;
- B2B-aware Customer authentication return semantics;
- Customer runtime/product error recovery and sanitized diagnostics;
- Dashboard live Driver map production failure hardening;
- authoritative Mobile Settings/AppVersion rollout-readiness presentation.

The Dashboard update is cumulative from the supported minimum boundary and may update an installation currently on 1.0.40. Publishing 1.0.41 does not modify production AppVersion rows, does not change production minimum-supported Driver version, and does not activate Driver location enforcement.

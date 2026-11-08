# FOODEX 1.0.125 — Synchronized Release Notes

## Identity

- Dashboard / repository: `1.0.125`.
- Customer App: `1.0.125+125`.
- Driver App: `1.0.125+125`.
- Van App: `1.0.125+125`.
- Immediate supported source: Dashboard `1.0.124`.
- Prior immutable tags and assets are never overwritten with a different source commit.

## Changes

- Scoped B2B/B2C Customer Groups, many-to-many memberships, multi-group bulk assignment and promotion audience guardrails.
- Multi-agent Customer Service assignment per order, preserving assignment records and role/store boundaries.
- Pre-dispatch, reasoned Customer Service percent-discount revisions with server-side reconciliation, audit, idempotency and customer notification.
- Current wholesale Customer Home storefront reads published management configuration rather than draft-only content.
- Internally bootstrapped singleton Platform Owner with a protected registry and separate Super Admin authority.
- Retain B2B VAN ONLY and B2C DRIVER ONLY dispatch constraints.

## Release acceptance

Publish only when all Required CI checks on the exact final commit pass. Release Factory must produce and validate the real setup/install and update archives, checksums, manifests and all three mobile APKs from the protected-main source. Perform a clean ZIP install and in-place upgrade from `1.0.124` with protected data/uploads/environment, backup and recovery validation. Publishing GitHub assets does not itself verify the live cPanel deployment; prove the dashboard's running version separately.


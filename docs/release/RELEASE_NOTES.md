# FOODEX 1.0.115 — Dashboard Hotfix Release Notes

## Release identity

- Dashboard / repository release: `1.0.115`.
- Requested operator label: `1.0.114+1`.
- Customer source identity remains: `1.0.114+114`.
- Driver source identity remains: `1.0.114+114`.
- Van source identity remains: `1.0.114+114`.
- This is a Dashboard/backend-only hotfix; mobile app sources and APK identities are intentionally unchanged.

## Included changes

- Expose Driver/Van assignment controls in unified Order Management for **Customer Service Supervisor** and **Customer Service Agent**.
- Grant only the existing `orders.dispatch` capability required for assign/reassign/unassign.
- Do not grant `orders.manage`, Driver live tracking, or Driver registry management.
- Keep tenant/store/channel scope and backend dispatch authorization authoritative.
- Include automated RBAC regression coverage.

## Build scope

- Dashboard cumulative Update Center package targeting 1.0.115.
- No Customer APK rebuild.
- No Driver APK rebuild.
- No Van APK rebuild.
- No mobile source-version bump.

## Deployment

- Install over FOODEX Dashboard 1.0.114 through the canonical Update Center/package.
- The updater requires canonical major.minor.patch targets, therefore the installable version is 1.0.115 rather than a literal 1.0.114+1 target.

## Validation contract

The hotfix is complete only after required CI is green, the exact Dashboard bundle is generated from the final release head, checksum/manifest validation passes, and the release is visible through the canonical Dashboard update path.

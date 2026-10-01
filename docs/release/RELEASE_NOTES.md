# FOODEX 1.0.43 Release Notes

Status: synchronized repair distribution for installed 1.0.42 systems affected by shared-host/cPanel public document-root 404s.

## Release identity

- Dashboard: `1.0.43`
- Customer app: `1.0.43+43`
- Driver app: `1.0.43+43`
- Customer runtime/footer identity: `1.0.43`
- Driver runtime/footer identity: `1.0.43`
- Driver diagnostics current identity: `1.0.43`
- Driver diagnostics build identity: `43`

## Deployable delta since distributed 1.0.42

### Shared-host public runtime repair
- Deliver the #670/#671 production fix under a new immutable release identity.
- Keep canonical Laravel static files under `backend/public/**`.
- Also ship safe release-root aliases for `assets/**`, `brand/**`, `demo/**`, and `preview/**` for supported shared-host/cPanel document roots.
- Normalize mirrored static file permissions to `0644` and directories to `0755` during update extraction.
- Do not mirror the Laravel front controller or `.htaccess` into the release root.

### Affected production surfaces
- FOODEX Economical Group branding.
- Leaflet runtime and Driver Live Tracking map.
- Customer Flutter Web App Preview runtime.
- Driver Flutter Web App Preview runtime.

## Dashboard update bundle

- Target version: `1.0.43`
- Minimum current version: `1.0.6`
- Contains migrations: `true`
- Requires full redeploy: `false`
- SHA-256: `03c50ab87e8819b2e6e9128b29a06218dcdacaf439f5b715a419d102361baf5e`
- Package files: `675`
- Package size: `72,999,571` bytes

## Explicit non-activation statement

This release does **not**:
- change production minimum-supported AppVersion rows;
- enable Driver fresh-location enforcement;
- enable the Assistant in production by default;
- change the Assistant from read-only by default.

# FOODEX 1.0.42 Release Notes

Status: synchronized production distribution built from the completed post-1.0.41 mobile production UX, self-contained App Preview runtime and Assistant V1 integration.

## Release identity

- Dashboard: `1.0.42`
- Customer app: `1.0.42+42`
- Driver app: `1.0.42+42`
- Customer runtime/footer identity: `1.0.42`
- Driver runtime/footer identity: `1.0.42`
- Driver diagnostics current identity: `1.0.42`
- Driver diagnostics build identity: `42`

## Deployable delta since distributed 1.0.41

### Driver production UX
- Restore production Dashboard static assets required by Driver Live Tracking.
- Add authoritative accepted → out-for-delivery → delivered/failed Driver lifecycle handling with notes/proof and Dashboard/Customer notifications.
- Ship the redesigned Driver Home, Deliveries and action modals while preserving location/session/version/diagnostics gates.

### Customer production UX
- Persist authenticated Customer sessions across restart and shopping navigation, with logout/expiry cleanup.
- Restore Dashboard-managed Retail banner/media delivery.
- Ship the redesigned Customer marketplace home using real Dashboard categories, products, banners and media.

### App Preview distribution
- Bundle Customer and Driver Flutter Web preview runtimes inside the Dashboard update package.
- Keep Preview deployment on the same Dashboard update path instead of requiring a separate runtime publication step.

### FOOD Assistant V1
- Integrate the deterministic Arabic/English FOOD Assistant using authoritative FOODEX business and operations data.
- No LLM, paid/external AI API or AI API key is required.
- Production defaults remain `ASSISTANT_ENABLED=false` and `ASSISTANT_READ_ONLY=true`.

## Validation
- Final #648 integrated acceptance merged green.
- Backend + MySQL/Redis acceptance passed.
- Customer/Driver Flutter and iOS no-codesign validation passed.
- Runtime screenshot evidence and APP-PREVIEW visual/pixel parity passed.

## Dashboard update bundle

- Target version: `1.0.42`
- Minimum current version: `1.0.6`
- Contains migrations: `true`
- Requires full redeploy: `false`
- SHA-256: `4ec933b9a0bcad5649b2e5c7a9cb3dc06a95de10d655a2c83a42b9c994c70f5f`
- Package files: `578`

## Explicit non-activation statement

This release does **not**:
- change production minimum-supported AppVersion rows;
- enable Driver fresh-location enforcement;
- enable the Assistant in production by default;
- change the Assistant from read-only by default.

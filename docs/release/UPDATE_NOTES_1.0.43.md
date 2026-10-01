# FOODEX 1.0.43 Distribution Notes

This release republishes the Dashboard update after `1.0.42` so production shared-host installations receive the public-runtime path repair merged in #670/#671.

Included:
- canonical `backend/public/**` static runtimes plus safe release-root aliases for `assets/**`, `brand/**`, `demo/**`, and `preview/**`;
- updater permission normalization for mirrored static files/directories;
- Leaflet and Driver Live Tracking assets in both supported document-root layouts;
- Customer and Driver Flutter Web Preview runtimes in both supported document-root layouts.

Dashboard Update Center metadata:
- target version: `1.0.43`;
- minimum current version: `1.0.6`;
- contains migrations: `true`;
- requires full redeploy: `false`;
- SHA-256: `03c50ab87e8819b2e6e9128b29a06218dcdacaf439f5b715a419d102361baf5e`;
- packaged file count: `675`;
- package size: `72,999,571` bytes.

Publishing 1.0.43 does not modify production minimum-supported AppVersion rows, does not activate Driver fresh-location enforcement, and does not enable the Assistant by default.

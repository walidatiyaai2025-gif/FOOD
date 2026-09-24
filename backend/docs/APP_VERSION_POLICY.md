# Mobile app version policy

FOODEX keeps one policy row per application (customer or driver) and platform (Android or iOS). The backend is the source of truth.

Clients call `GET /api/v1/app-version` with `platform`, `app`, and `current_version`. The response status is deterministic:

- `current`: installed version is at least latest.
- `optional`: below latest but still supported and the policy does not force the update.
- `forced`: below latest, still supported, and force-update is enabled.
- `unsupported`: below minimum supported; update is always required and forced.

The API returns only the configured official App Store / Google Play URL. Production mobile applications must open that URL for updates and must never download or sideload application packages.

Only users authorized for `platform.manage` can edit policy records. Every policy mutation is written to the audit log. Validation rejects unknown apps/platforms, malformed versions, invalid store URLs, and minimum versions greater than latest.

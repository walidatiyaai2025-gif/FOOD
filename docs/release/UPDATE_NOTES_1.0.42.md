# FOODEX 1.0.42 Distribution Notes

This release publishes the complete post-`1.0.41` integrated mobile production UX, self-contained App Preview runtimes and FOOD Assistant V1 under the immutable identity `1.0.42` / mobile build `+42`.

Included:
- Driver production asset reliability, delivery lifecycle/notifications and redesigned Home/Deliveries UX;
- Customer persistent authentication, Dashboard-managed Retail banners and redesigned marketplace home;
- Customer/Driver Flutter Web Preview runtimes inside the Dashboard update package;
- deterministic FOOD Assistant V1 with Arabic/English chat, authoritative FOODEX data, no LLM/external AI API, disabled-by-default and read-only-by-default;
- final integrated #648 validation evidence.

Dashboard Update Center metadata:
- target version: `1.0.42`;
- minimum current version: `1.0.6`;
- contains migrations: `true`;
- requires full redeploy: `false`;
- SHA-256: `4ec933b9a0bcad5649b2e5c7a9cb3dc06a95de10d655a2c83a42b9c994c70f5f`;
- packaged file count: `578`.

Publishing 1.0.42 does not modify production minimum-supported AppVersion rows, does not activate Driver fresh-location enforcement, and does not enable the Assistant by default.

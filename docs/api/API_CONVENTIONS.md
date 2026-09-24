# API Conventions

Base path: `/api/v1`.

- JSON payloads use snake_case.
- Timestamps use ISO-8601 and UTC.
- Money uses decimal values plus explicit ISO currency.
- 401 = unauthenticated; 403 = authenticated but unauthorized; 404 may hide resources outside authorized scope.
- Validation failures use 422 with a stable error envelope.
- Collection endpoints use stable pagination metadata.
- Retriable payment/order commands require idempotency when duplicate execution could create financial or order side effects.
- Any API change updates `docs/api/openapi.yaml` in the same PR.

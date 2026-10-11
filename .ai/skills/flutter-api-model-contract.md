# Skill: Flutter API and Model Contract

Use when Flutter code touches HTTP, URI construction, JSON serialization/deserialization, DTO/domain models, repositories or response/status handling.

## Authority
Read the existing FOODEX API/client/model contract before writing. Do not infer a new payload shape from UI wording or a screenshot.

## Endpoint rules
- use the repository's configured base URL/client; never add a second production base URL in feature code;
- preserve authentication, store/channel/account scope and existing headers;
- encode paths/query parameters safely;
- do not log secrets, bearer tokens, credentials or sensitive payloads.

## Parsing
- parse external JSON defensively and type boundaries deliberately;
- required vs optional fields must be explicit;
- tolerate contract-approved missing/null fields without unsafe casts;
- do not convert malformed authoritative values into plausible fake defaults;
- keep enum/status mapping centralized when a shared model already owns it.

## Failure semantics
Handle relevant timeout/network/non-2xx/validation/auth cases. A redirect or HTTP success code alone is not sufficient proof of a business mutation if the API contract requires persisted-state confirmation.

## Mutations
Financial/order/payment/dispatch/collection/remittance operations must preserve idempotency/deduplication rules and authoritative server totals/statuses.

## Tests
Use hermetic fakes/fixtures at the HTTP boundary. Cover success plus at least the changed error/nullability/compatibility behavior. Never point automated tests at production.

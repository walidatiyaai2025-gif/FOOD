# Skill: API Endpoint

## Contract first
Identify method/path under `/api/v1`, actor/auth guard, store/channel scope, request schema, response schema, business errors, idempotency/retry semantics, consuming clients and OpenAPI impact.

## Implementation
- Validate at the HTTP boundary.
- Authorize server-side.
- Resolve record ownership/scope before mutation.
- Put business transitions in the appropriate Action/Domain/Service layer.
- Use transactions for coupled writes.
- Do not trust client-supplied state the server can derive.
- Avoid leaking internal exceptions, secrets or storage paths.

## State-changing endpoints
Test duplicate/retry behavior, stale state, unauthorized/other-store records, race-sensitive invariants and audit/notification duplication where applicable.

## Compatibility
Search Dashboard, Customer, Driver and Van consumers before changing existing response fields/enums.

Update OpenAPI and contract tests with the code change.

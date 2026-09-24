# Observability foundation

FOODEX attaches an `X-Correlation-ID` to API requests and responses and adds it to structured log context. Clients may supply an existing correlation ID for end-to-end tracing.

`GET /api/v1/health` checks critical database and local-storage dependencies and returns only safe boolean dependency states. It never returns credentials, connection strings, paths, stack traces or exception messages.

Unhandled API exceptions are logged server-side with the correlation ID and exception class. Public JSON responses use a generic message and do not expose sensitive runtime internals.

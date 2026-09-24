# Audit logging foundation

Critical domain changes emit server-side audit entries through `App\Services\AuditLogger`.

Entries record actor when known, event, target type/id, before/after values, IP address and user agent. Passwords, tokens, cookies, OTP values, API keys and secrets are recursively replaced with `[REDACTED]` before persistence.

Audit logging does not replace authorization: server-side permission and store-scope checks remain mandatory. Use stable event names and include only business fields needed to explain the change.

Retention is deployment policy. Records are retained unless an explicitly approved retention process removes them. This foundation exposes no public audit API.

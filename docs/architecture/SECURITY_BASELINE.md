# Security Baseline

- Passwords use Laravel hashing; plaintext passwords are never stored.
- API/session authentication strategy is finalized in M1 and documented in OpenAPI.
- Rate limiting is required on authentication, checkout, payment and other abuse-sensitive endpoints.
- CSRF protection applies to stateful web flows; CORS is explicit for API clients.
- Authorization policies and store isolation are mandatory.
- RBAC system roles and canonical permissions are config-authoritative: every role/permission mutation reconciles the built-in role/permission database state before and after the write, preserves custom roles and valid user-scoped overrides, and audits the reconciliation.
- Per-user permission exceptions are explicit scoped `allow`/`deny` overrides on top of the assigned role baseline; they never mutate the shared role, never bypass channel/store boundaries, and are reset when the user's assigned role actually changes.
- Critical mutations produce audit logs.
- Uploads validate type/size/path and never trust client filenames.
- Update packages require signature or cryptographic hash validation.
- Successful installation writes an installer lock and disables /install.
- Update Center requires privileged authorization.
- Secrets stay in environment/secret stores, never Git.
- Backup files and DB dumps require restricted storage permissions.

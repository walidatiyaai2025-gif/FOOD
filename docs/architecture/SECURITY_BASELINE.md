# Security Baseline

- Passwords use Laravel hashing; plaintext passwords are never stored.
- API/session authentication strategy is finalized in M1 and documented in OpenAPI.
- Rate limiting is required on authentication, checkout, payment and other abuse-sensitive endpoints.
- CSRF protection applies to stateful web flows; CORS is explicit for API clients.
- Authorization policies and store isolation are mandatory.
- Critical mutations produce audit logs.
- Uploads validate type/size/path and never trust client filenames.
- Update packages require signature or cryptographic hash validation.
- Successful installation writes an installer lock and disables /install.
- Update Center requires privileged authorization.
- Secrets stay in environment/secret stores, never Git.
- Backup files and DB dumps require restricted storage permissions.

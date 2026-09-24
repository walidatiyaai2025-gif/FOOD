# Authentication foundation

FOODEX v1 uses Laravel Sanctum personal access tokens for first-party mobile and web API clients.

## Contract

- `POST /api/v1/auth/login` accepts email and password and returns a Bearer token plus the authenticated identity contract.
- `POST /api/v1/auth/logout` revokes the current token.
- `GET /api/v1/profile` returns the current identity.
- Login is limited to five attempts per minute per normalized email and client IP.
- Inactive users and invalid credentials receive the same validation response.
- A successful login revokes previous API tokens for that user so the baseline behaves as a single active API session.
- B2B self-registration is deliberately not exposed. B2B accounts are provisioned from the management dashboard only.

## Authorization boundary

Authentication proves identity only. Role/permission enforcement and mandatory store scoping are implemented by Issues #11 and #12. The identity response exposes current role codes and assigned store IDs so clients can render navigation, but backend authorization remains authoritative.

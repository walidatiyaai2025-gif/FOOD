# V2 #2740 — MOB-ALL01 cross-app identity/token authorization (bounded source fix)

**Source:** FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx P009/P032/P144 and MOB-ALL01; master #2503; integration **Foodex_V2 ONLY**.

## Verified discrepancy and implementation

Before this change `AuthController::register` issued an unscoped wildcard Sanctum token to newly registered Customer identities. `AuthController::mobileTrialLogin` likewise issued an unscoped token for both `customer` and `driver` trial login. Since `EnsureDriverRuntimeAccess` and `EnsureVanRuntimeAccess` enforce `tokenCan('app:driver')` / `tokenCan('app:van')`, a wildcard token can pass the **app-specific token** gate if the same identity later acquires additional valid runtime roles/assignments. Role/assignment guards also apply, so this is **not** a claim of existing privilege-free runtime execution.

**Fix:** Registration now issues only `['app:customer']`. Trial login now issues only `['app:customer']` or `['app:driver']` corresponding to validated `app`. Explicit `/auth/login` `app:customer|driver|van` already issues correctly scoped tokens and is not changed. The legacy `/auth/login` path with app omitted deliberately retains its existing wildcard semantics for backward compatibility; this is **residual risk** requiring separate contract/versioned migration review before changing legacy callers. Previously issued wildcard tokens are not revoked or migrated by this patch; plan inventory and safe revocation in a separately owned security rollout if product authority approves.

## Runtime authorization matrix

| Token provenance | Abilities issued | Customer identity routes | Driver runtime | Van runtime |
| --- | --- | --- | --- | --- |
| Customer self-register | `app:customer` | Existing authenticated identity/profile access | Denied by `EnsureDriverRuntimeAccess` token gate | Denied by `EnsureVanRuntimeAccess` token gate |
| Username trial, Customer | `app:customer` | Existing authenticated identity/profile access | Denied at token gate even after actor gains B2C Driver role | Denied at token gate even after Van assignment |
| Username trial, Driver | `app:driver` | No new customer privilege granted | Existing B2C driver identity, active driver record and `deliveries.b2c.execute` still required | Denied at token gate despite legitimate Van assignment |
| Explicit password login, Driver | `app:driver` | Existing API policy unchanged | B2C only, server-authorized | Denied at token gate |
| Explicit password login, Van | `app:van` | Existing API policy unchanged | Denied at token gate | Must have effective Van assignment and runtime authorization |
| Legacy password login without app | `*` (pre-existing) | Existing controls | Role/driver record guards still enforced | Effective Van assignment/permission still enforced |

**Do not conflate token scope with role scope.** A token is an app audience constraint, not permission to perform an action. The current active.user middleware also rejects inactive users. B2B=Van-only and B2C=Driver-only delivery guarantees remain unchanged.

## Test evidence and isolation

New isolated `backend/tests/Feature/V2CrossAppTokenScopeMatrixTest.php` uses real registered Customer credentials, later B2C Driver + effective Van assignment, and trial dual-role accounts. It verifies actual stored Sanctum token abilities and authenticated HTTP requests to `/api/v1/driver/wallet`, `/api/v1/van/no-order-reasons` and `/api/v1/profile`, plus explicit Driver/Van app login and denial under cross-app tokens. No new route/schema, no user secrets, no token exposed in logs, and no settlement mutations.

- **Customer App:** Customer registration and trial token abilities are narrowed; no Dart/API shape changes. Existing authenticated Customer reads continue to use Sanctum and account-scoped business controllers.
- **Driver App:** Trial token abilities narrowed to `app:driver`; existing driver login remains `app:driver`. Cannot call Van runtime with Driver trial token. B2C only.
- **Van App:** No minting change to explicit `app:van` login; both Customer and Driver trial tokens denied from Van routes even for dual-role actors; effective assignment and Van permission still required. B2B Van execution invariant untouched.
- **Dashboard:** Not affected; no navigation, Blade or admin RBAC mutations.
- **Security:** Legacy wildcard caller migration and existing wildcard-token inventory are out of this bounded fix; do not assert absolute cross-app isolation for legacy tokens.

## Outstanding MOB-ALL01 acceptance

This patch does **not** certify all three Flutter runtime E2E paths, the whole P009/P032/P144 role/permission matrix, or historical token migration. Keep #2740 OPEN until the full source/API/mobile parity and exact-head acceptance evidence are verified. Do not force unrelated authentication policy changes into this issue or touch an active mobile worker's files.

**CI:** Required Repository Policy, FOODEX V2 Isolation, FOODEX Required CI Gate (full Laravel, security/Pint/PHPStan, localization, MySQL/Redis acceptance as applicable) must all be GREEN on the exact final PR HEAD before merging to Foodex_V2. No main, releases, tags or deployment.

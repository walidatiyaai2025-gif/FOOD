# V2 #2702 — VAN_OPERATOR authority and Van-app denied-login contract

**Source:** FOODEX Unified RBAC/ERP/Sidebar Discussion dated 2026-11-09, table T122R22. Parent #2503. Canonical branch \`fix/2702-v2-van-operator-access-denial-contract\`. Only PR target: \`Foodex_V2\`.

## Requirement and placement

- Actor: built-in role \`VAN_OPERATOR\` / مشغل الفان, field-operations global assignment with tenant business scope.
- The role grants **exactly \`van.login\`**, not Super Admin, Dashboard dispatch, finance, order approval, B2C Driver execution or privileged reports.
- \`van.login\` alone is **not sufficient**. An active, effective, specifically owned Van assignment is mandatory. The back end resolves \`van_scope\` and issues an \`app:van\`-limited Sanctum token; API runtime middleware verifies the permission and assignment again per request.
- **Placement decision:** Reuse the existing Van app login/session entry point and Van runtime API. No new Dashboard Sidebar page, app screen or authorization policy. The Van app is the canonical operator interface; a standalone Dashboard menu for \`VAN_OPERATOR\` would be misleading and a security regression.
- Business context: field operations / Van / assigned operator / operational entry and task execution / daily / existing Van-app compact data-first layouts / existing AR RTL and EN LTR / access-denied and offline states. Auth errors are app-domain state, not a UI navigation grant. No user-facing copy or visual control changes.

## Verified implementation targets

| Layer | Contract and regression |
|---|---|
| Laravel RBAC/role | \`BuiltInJobRoleCatalog\`, \`config/job_roles.php\`, seeded \`VAN_OPERATOR\`: exactly \`van.login\`; Arabic and English presentation from \`lang/{ar,en}/job_roles.php\` |
| Backend login | Actual POST \`/api/v1/auth/login\` with \`app=van\` requires valid credentials, permission and effective Van assignment; forged \`van_id\` or another operator's assignment denied before token issuance |
| Backend runtime | \`EnsureVanRuntimeAccess\` verifies \`app:van\` token + *current* effective assignment; ended assignments and removed role deny existing tokens; \`/api/v1/driver/wallet\` denies a Van-scoped token |
| Backend overrides and account status | Explicit global \`van.login\` DENY cannot be bypassed by built-in role; inactive identity cannot log in; no token created |
| Van Flutter login | \`HttpVanAuthRepository\` maps 401/422 to authentication errors, **403 to \`VanAccessDeniedException\`**, network failures to offline, malformed or other server failures to API error. App does not treat a permission denial as a retryable generic server problem |
| Van Flutter session parser | A successful response without a positive \`assignment_id\` is still denied by \`VanSession.canUseVan\`; protected requests on 401 expire sessions and on 403 report access denial |

Tests:
- \`backend/tests/Feature/V2VanOperatorAuthorityTest.php\` — seeded role exact grant/AR-EN, assignment and unauthorized van selection, real Sanctum app token/driver isolation, ended assignment and removed role revocation, explicit DENY and inactive identity.
- \`apps/van_app/test/van_auth_contract_test.dart\` — HTTP 403 permission-denied handling, no effective assignment rejection, existing happy path and session-error tests.
- No local user account secrets, real credentials or production endpoints are used in tests. No new API routes, contracts, schema, tokens, role grants or runtime fallback created.

## Cross-app and Dashboard impact matrix

| Surface | Changed | Impact |
|---|---|---|
| Backend | Regression test only | Existing real endpoints, RBAC, scoped assignment and app-specific Sanctum middleware exercised; no API schema change |
| Admin Dashboard | None | VAN_OPERATOR remains mobile-only operator; existing admin Fleet/Van assignment management is unchanged |
| Customer Flutter | None | No customer identity/storefront/checkout permission or response change |
| Driver Flutter | None | No Driver code or driver-only access change; token-isolation is regression-tested |
| **Van Flutter** | **Yes** | Existing HTTP login adapter's unauthorized HTTP 403 is normalized to the existing \`VanAccessDeniedException\` type; no screen, token storage, identity schema, offline queue or visual contract change |

**Owner rule:** B2B wholesale fulfillment = Van only; B2C retail fulfillment = Driver only. This PR does not authorize a B2C Van fulfillment fallback. The separate broader lifecycle/assignment audits #2737 (revoke/offline identity), #2738 (B2B-only routes/territories/assignments), #2739 (stock/offline replay) remain independently open and are not represented as complete by this role/login fix.

## Acceptance / safety

- AR/EN role names present and no new untranslated strings.
- No raw server errors/credentials, stale permission impersonation or cross-app token reuse.
- Only close #2702 after backend + affected Van Flutter tests and applicable exact-HEAD Repository Policy, V2 Isolation, backend/Flutter validation, localization, security and terminal Required CI gate succeed. An irrelevant job may be skipped by the repository's own area classifier; no silent override.
- Original V2 owner decision postpones manual UAT/staging/device checks to the program final gate (#2503/#2750); no production-release, ZIP/APK or live deployment claim.

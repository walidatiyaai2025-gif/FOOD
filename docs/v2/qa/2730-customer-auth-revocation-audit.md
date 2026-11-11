# V2 #2730 / MOB-C01 — Customer session/account revocation and role-denial boundary

**Source:** FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, MOB-C01 (P069/P070/P079/P142). Parent #2503; **Foodex_V2 ONLY**. No `main` merge, production, release, tag or APK publication.

## Canonical ownership and affected surfaces

- **Backend identity authority:** `backend/app/Http/Middleware/EnsureActiveUser.php` checks `is_active`, deletes all Sanctum tokens and returns HTTP 401 for disabled identities. This is the authoritative remote revocation contract; the mobile app cannot authorize a disabled user offline. Backend role- and store-scoped denials remain 403/404 as appropriate, and do **not** necessarily mean the customer account itself was disabled.
- **Existing Customer session:** `CustomerSessionHttpClient` recognizes authenticated 401, `FoodexCustomerApp._onSessionExpired()` clears secure persisted session, revokes push binding, clears diagnostics/guest state and routes to `CustomerRoutePaths.entry`. Secure remember-me/biometric persistence is `customer_auth_persistence.dart` + `customer_session_store.dart`; no credentials are copied into logs or storage.
- **Bug / concrete fix:** a request made with an *old* token can finish with a 401 after the user signs into a *new* session. Previously its bare `onUnauthorized()` callback revoked the new valid session. The HTTP wrapper now extracts the rejected Bearer token and calls an optional `isCurrentToken` guard; `app.dart` binds the guard to the app's **current authoritative session**, without caching or duplicating token state. Old-session 401 cannot erase new-session credentials, but 401 on the actual current bearer still signs out immediately.
- **403:** a denied route (for example after a scope/role change without complete account disable) remains a permission failure and must **not** globally revoke a still-authorized account. Existing screen-level API error handlers preserve the appropriate denied/error UI.
- **UI / AR-EN:** no new route, visible string, page, button, permission, or application color. The existing bilingual login/session-invalid navigation remains unchanged in RTL/LTR; the race is transport lifecycle only.

## Hermetic Flutter evidence

`apps/customer_app/test/v2_customer_session_revocation_race_test.dart` asserts:

1. late old-token 401 after successful new-token switch must not clear new session;
2. current-token revoked 401 clears the session exactly once (including duplicate late retries);
3. authenticated permission-only 403 must retain token;
4. rejected unauthenticated login without a Bearer header cannot clear a saved token;
5. bearer scheme casing does not bypass server-authoritative invalidation.

Maintain `customer_session_http_client_test.dart` and `customer_auth_persistence_test.dart` baseline. CI should execute `flutter analyze`, changed-file fatal-info, hermetic Customer tests, localization and required repository policy/isolated integration gates. A manual device test with biometric unlock, account deactivation and login switch is deferred under #2503 owner's explicit V2 FINAL acceptance override; do not claim it passed.

## Cross-app assessment

| Surface | Classification | Reason |
| --- | --- | --- |
| Laravel Backend/API | Audited, no code change | Existing active-user Sanctum revocation path retained, status semantics unchanged |
| Customer Flutter | **Affected** | Authenticated HTTP transport/current-session matching and new race tests |
| Driver Flutter | Not modified | Separate Driver token/session and access-denial lifecycle; dedicated #2734 remains an independent V2 verification |
| Van Flutter | Not modified | Separate Van/offline identity and revocation lifecycle; dedicated #2737 remains independent |

**Closure boundary:** this PR proves the Customer stale-401 invalidation race and existing current-token semantics; it is **not** by itself evidence that every source P069/P070/P079/P142 requirement (account archive, role mutation lifecycle, full API tests/device UAT) is complete. Keep #2730 open if any of those code/automated tests remain unproven, even if this isolated PR is merged. Only manual UAT can be deferred, not missing implementation or CI.

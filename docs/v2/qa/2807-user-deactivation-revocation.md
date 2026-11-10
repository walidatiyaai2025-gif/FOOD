# V2 #2807 / P069 — Immediate deactivation revocation

## Authority and placement
Existing Security Center Users status action and `/api/v1/admin/security/users/{user}/status` remain the only authorized mutation surfaces. The `users.status.manage` Gate remains the server authority; no new user-facing screen, menu, API schema, or independent visibility ACL is introduced. Security Center uses existing bilingual AR/EN, RTL/LTR, responsive and accessible controls.

## Defect and correction
Before this scoped fix, `RbacManager::setUserActive` removed Sanctum tokens and set `is_active=false`, but did not revoke `admin_security_sessions` or rotate a persistent remember-me token. A deactivated management user was denied 403 by dashboard middleware without logging out the current web session. With subsequent reactivation the old browser credentials could potentially be reused.

Deactivation now rotates the remember token, revokes all registered management sessions and all Sanctum tokens inside the same user-state transaction. Reactivation does **not** restore old tokens or sessions. Inactive management identities are logged out and their sessions invalidated on their next protected HTTP request while preserving the established HTTP **403** denial contract; their next unauthenticated browser request redirects to the bilingual login. User ID, roles, history and audit log are retained. Session-revocation is the server-side kill-switch even on session stores where arbitrary cross-user session deletion is unavailable. Browser session cookie removal takes place on its next request; this is not a claim of remotely deleting a cookie from an offline browser.

## Access and impact matrix
| Surface | Impact | Evidence |
| --- | --- | --- |
| Backend / Dashboard | Affected; server-side revoked session registry and forced inactive-session logout | `V2UserDeactivationRevocationTest`; existing `EnterpriseRbacTest` |
| API | Existing `active.user` rejects inactive identities and existing Sanctum tokens are immediately deleted; response/route unchanged | `EnterpriseRbacTest::test_deactivated_user_cannot_use_api` |
| Customer app | No Flutter source/runtime contract change. Existing `foodex-customer` token is invalidated by the canonical deactivation path | shared app-token revocation test |
| Driver app | No Flutter source/runtime contract change. Existing `foodex-driver` token is invalidated by the same path | shared app-token revocation test |
| Van app | No Flutter source/runtime contract change. Existing `foodex-van` token is invalidated by the same path | shared app-token revocation test |

## Validation and operational caveats
Focused regression: identity and role preservation; selective management-session revocation; all three existing app token classes; remember-me rotation; reactivation non-resurrection; status Gate denial; forced web logout and history audit. Other existing tests cover Platform Owner protection, last Super Admin, API 401, scope and channel denial. Final exact-head CI, MySQL/Redis acceptance and integration status must be linked in the Issue/PR before this leaf is considered complete.

Target integration: **Foodex_V2 only**. No `main` merge, tag, release, APK distribution, production or cPanel deployment. Manual staging/UAT remains deferred to final V2 per #2503; this deferral does not waive required automated checks.

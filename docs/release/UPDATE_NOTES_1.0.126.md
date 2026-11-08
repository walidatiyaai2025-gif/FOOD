# FOODEX 1.0.126 Update Notes

- **Supported direct source:** v1.0.125, subject to the published signed manifest and Updater origin verification.
- **Customer App:** **Sign up** is once again visible immediately below **Sign in** on the main login page. Arabic locale shows **إنشاء حساب** below **تسجيل الدخول**.
- **Registration:** selecting Sign up opens the existing form with name, email, telephone, password and password confirmation. Creating the account uses the canonical `/api/v1/auth/register` endpoint; no bypass of server validation, duplicate account protection or tenant boundaries.
- **Stability:** preserves existing login, remembered session, biometric access, post-auth routing and the B2B VAN ONLY / B2C DRIVER ONLY fulfillment policies. Driver and Van functional behavior is unchanged.
- **Release packaging:** from one final protected-main Git SHA produce a complete Laravel Setup ZIP, compatible Update ZIP, manifest, signature/integrity evidence, build info, release notes and synchronized Customer/Driver/Van 1.0.126+126 APKs. Verify full fresh-install and upgrade acceptance before publishing; do not overwrite the already-published v1.0.125 assets.
- **Hosting:** applying the update on a deployed cPanel instance still requires verifiable install/update and post-deploy health checks; a published GitHub release alone does not prove that live hosting changed.

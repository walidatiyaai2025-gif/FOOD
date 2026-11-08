# FOODEX 1.0.126 — Customer Sign up Hotfix

## Release identity
- Dashboard and backend: `1.0.126`
- Customer App: `1.0.126+126`
- Driver App: `1.0.126+126`
- Van App: `1.0.126+126`
- Minimum direct Dashboard upgrade source: `1.0.125` when confirmed by signed release manifest.

## Changes
- Restore **Sign up / إنشاء حساب** directly underneath **Sign in / تسجيل الدخول** in the Customer App login screen (Issue #2487).
- The button opens the existing protected Customer self-registration flow rather than an external URL or placeholder.
- Arabic and English, RTL/LTR, registration form navigation and return to login have widget regressions.
- No changes to Driver/Van logic, customer authentication credential policy, wholesale VAN ONLY, retail DRIVER ONLY, or tenant isolation.

## Release assurance
- v1.0.125 is immutable and remains unchanged. This follow-up patch is the safe way to distribute the signup fix.
- Publish only after exact-head Required CI, Release Validation, Android release APK build and package authenticity checks, clean Setup ZIP installation, 1.0.125 → 1.0.126 Update ZIP upgrade, migrations and recovery tests.
- Validate GitHub release artifacts and protected registry; separately prove the version deployed on the live cPanel dashboard.


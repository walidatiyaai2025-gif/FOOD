# V2 P012 — Help and Profile link authorization contract

- Issue: #2757; parent: #2503; source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx `P012`.
- Integration: **Foodex_V2 only**, no main, production release, or live cPanel action.
- Placement: **Reuse** existing `public.support` and `admin.profile.index` destinations. Do not create duplicate help/profile screens or grant access based on a Sidebar link.
- Existing implementation: merged #2876 / PR #3016 exposes help and profile in the authorized Dashboard utility bar; existing `V2SidebarUtilityToolsTest` exercises AR/EN, RTL/LTR, accessible affordances, link rendering, and locale update audit. P012 adds an independent route-level protection regression suite.

## Policy, actors and route inventory

| Surface | Actor | Route/action | Permission and boundary |
| --- | --- | --- | --- |
| Help | Guest, active B2B, active B2C | GET `/support`, `public.support` / `PublicInformationController::support` | Deliberately public information, independent of Dashboard role/Sidebar visibility. `lang=ar|en` resolves direction and localized copy. Not an administrative permission. |
| Profile | Active authorized B2B Dashboard user | GET `/admin/profile`, `admin.profile.index` / `AdminProfileController::index` | `management.dashboard` and ERP readiness middleware group; caller must be active and `AdminNavigation::canUseDashboard` must authorize. Shows caller identity and assignments only. |
| Profile | Active role-assigned B2C store actor | Same protected GET | Role/store-scoped access; profile link never authorizes `admin.b2b.dashboard` or foreign-store routes. |
| Profile | Guest | Same protected GET | Redirect to existing `admin.b2c.login`. |
| Profile | Inactive or unassigned actor | Same protected GET | HTTP 403; direct URL cannot be unlocked by adding a rendered link or changing Sidebar appearance. |

Profile mutations remain distinct protected endpoints (`admin.profile.password`, `admin.profile.locale`) with their existing validated HTTP verbs, audit, CSRF, and session/token protections. No new mutation/API contract is introduced in P012. Financial maker/checker, module entitlement and dispatch are unrelated to this read-only navigation policy; no rights are implicitly elevated.

## Test and safety evidence

New `backend/tests/Feature/V2HelpProfileLinkPolicyTest.php` locks down route middleware, public bilingual help for guests, profile guest rejection, authorized B2B AR/EN profile, Store A actor's allowed profile versus denied wholesale and Store B, and inactive/unassigned direct-URL 403. Existing `V2SidebarUtilityToolsTest` covers the actual Sidebar links and accessible layout, so there is no duplicate UI logic.

- PHPUnit: `cd backend && php artisan test --filter=V2HelpProfileLinkPolicyTest`.
- Formatting: `cd backend && vendor/bin/pint --test tests/Feature/V2HelpProfileLinkPolicyTest.php`.
- Required gate: Repository Policy, FOODEX V2 Isolation, Required CI (Laravel, localization, static analysis, MySQL/Redis install/upgrade/recovery) all GREEN at the exact PR head before merge.
- Runtime acceptance still requires independent browser verification (desktop/mobile, 320–1280px, keyboard/focus and AR/EN), not merely a successful unit test. Keep issue open if this evidence is missing.

## Mobile impact matrix

| App | Classification | Evidence |
| --- | --- | --- |
| Customer | NOT AFFECTED | No `/api/v1`, shared auth, mobile deep link, Flutter files or response schema changed. |
| Driver | NOT AFFECTED | No Driver authentication, GPS, delivery/dispatch or API files changed. |
| Van | NOT AFFECTED | No Van authentication, assignments, visits, routing or B2B fulfilment files changed. |

B2B **VAN ONLY**, B2C **DRIVER ONLY**, store-scoped RBAC, financial SoD, and audit/history are unchanged. No migrations, data writes, release package, installer, update, or deployment introduced.

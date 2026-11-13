# V2 P317 — Applications & Digital Channels group authority

- **Issue:** #2647; parent #2503. Source Sidebar matrix `P317`, actual DOCX paragraph `P306`.
- **Integration:** `Foodex_V2` only. No changes to `main`, releases, deployment, cPanel or live data.
- **Placement:** REUSE the canonical `applications` navigation group in `AdminNavigation::groupsFor` and `admin_sidebar.php`. No new route, independent Sidebar security decision, duplicate screen or category.
- **Language:** `admin.nav_groups.applications` conveys the P317 group title exactly in Arabic and English. Existing child titles remain localized via their own keys; RTL/LTR is inherited from the shared Dashboard shell.
- **Canonical children:** App Preview (`app_preview.view` in effective tenant/store scope); Customer/Driver/Van APK links (each `platform.manage`). Empty groups are removed. APK links remain release-version-locked and the admin controller independently requires `Gate::authorize('platform.manage')`.
- **Product ownership:** Restaurant SaaS remains under Stores & Channels with restaurant-profile, entitlement and route/permission checks; related P318/P325/P329 Issues retain their own source acceptance. Do not make restaurant routes globally accessible by adding them here.
- **HTTP and deny evidence:** `V2ApplicationsDigitalChannelsGroupTest` exercises real navigation, named routes, Super Admin, restricted B2B admin, roleless denial, assigned Retail scope, foreign-store non-disclosure, store override DENY and HTTP 403 across protected endpoints. Also proves no duplicate Smart Ordering item.
- **Mobile impact:** **Customer — no Flutter or API change. Driver — no Flutter or API change. Van — no Flutter or API change.** Download destinations are Dashboard-only protected navigations to existing APK artifacts, not mobile contracts.
- **Security:** No new grants, bypasses, tenant scope relaxation, mutable operations or database migrations. No module/readiness policy change. Missing artifact is already handled by the existing non-mutating Dashboard release hub fallback.
- **Acceptance pending:** exact-head CI (`Repository Policy`, `FOODEX V2 Isolation Gate`, `FOODEX Required CI`, Laravel/MySQL, AR/EN); real-browser responsive/RTL/LTR and final owner acceptance remain under #2750/#2503 if not separately evidenced.

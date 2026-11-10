# V2 #2876 / P165 — Sticky authorized Sidebar utility toolbar

**Source:** FOODEX Unified RBAC/ERP/Sidebar Discussion 2026-11-09 P165, master #2503. **Integration exclusively Foodex_V2.** No main release, tags, or production.

## Placement and ownership
- Domain: authorized Dashboard navigation; entity: shared Sidebar shell; actor: active B2B or assigned-store B2C Dashboard user; goal: quick access to favorites, last visited pages, help, profile and language without scrolling to the end of the full Sidebar tree. Frequency: frequent auxiliary navigation, data density: five compact controls.
- Route/entry: canonical `admin._sidebar`, existing `AdminNavigation::groupsFor` and RBAC, navigation_type `SIDEBAR`. Decision: **Reuse → Extend**, *not* an additional Sidebar/router/administration page.
- **Favorites:** opens existing `_sidebar-personalization` authorized favorites panel (or enters existing personalize mode if empty), without creating URLs.
- **Recent:** user-scoped localStorage **opaque item keys only**; every render and click resolves keys exclusively from current server-rendered authorized Sidebar anchors bearing `data-nav-item`. Invalid/stale, foreign store/channel or hidden module keys are discarded. No stored URLs, server session or side effects; capped at five. The control has a labelled region, empty state, dismiss via Escape or outside click. Existing group favorites and ordering behavior unchanged.
- **Help:** existing public `public.support` route; no new assistant privilege.
- **Profile:** existing authenticated `admin.profile.index` route. **Language:** existing audited `PATCH admin.profile.locale` with CSRF and validated AR/EN enum (no unsafe GET mutation).
- The footer is the last element of existing Sidebar shell and uses sticky logical bottom, FOODEX tokens and 44px touch targets; 5-column compact layout mobile, single-column icon mode in 82px collapsed desktop. AR RTL / EN LTR and screen-reader labels. Existing mobile drawer open/close/focus logic untouched.

## Authority and security
All real navigation targets remain backend-authenticated and role/store/module/entitlement gated. The Toolbar does not infer or grant route access. Local storage is presentation-only and cannot invent an href, resurrect a hidden/disabled module or use old store URLs. Public support route is intentionally public. Profile locale is server-side validated and audited. No new route, controller, DB table, API, session policy, permission code or background worker.

## Impact matrix
| Surface | Impact | Evidence |
| --- | --- | --- |
| Backend Laravel Dashboard | Affected | Existing authenticated profile/locale and public help routes reused; only Sidebar Blade presentation changes |
| Dashboard mobile web | Affected | Sticky tools with logical bottom, 44px targets and responsive compact/collapsed behavior |
| Customer Flutter | Not affected | No Customer route, API, auth/deep link, payload or Flutter source changed |
| Driver Flutter | Not affected | No Driver dispatch/delivery route, API or Flutter source changed |
| Van Flutter | Not affected | No Van route, van-only fulfillment, collection or Flutter source changed |

B2B VAN ONLY; B2C DRIVER ONLY, finance maker-checker and tenant isolation unchanged.

## Acceptance evidence
- `V2SidebarUtilityToolsTest`: real AR RTL / EN LTR HTTP Sidebar rendering, five controls, real safe destinations and CSRF/PATCH locale, persisted locale/audit, active B2B and assigned-store B2C, cross-channel 403, foreign store 404, inactive and unassigned denied. `AdminNavigationAuthorizationAuditTest`, `V2SidebarNoRbacEscalationTest` remain regressions.
- Required exact-head Repository Policy, V2 Isolation and Required CI including PHP syntax/Pint/PHPStan/Composer audit/OpenAPI, AR/EN, full Laravel tests, real MySQL Redis fresh install/upgrade/recovery must pass on PR before isolated squash merge.
- Real isolated staging keyboard/VoiceOver/mobile drawer touch/focus, history popup and AR/EN at 320/360/390/412/430/768/1024/1280px **not claimed as verified by CI**. Keep Issue OPEN for independent browser acceptance if needed. No speculative full release readiness claims.

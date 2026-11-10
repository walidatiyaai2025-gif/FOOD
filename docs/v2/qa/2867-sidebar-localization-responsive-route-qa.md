# V2 P145 — Sidebar AR/EN, mobile/desktop and route authority QA

- Issue: #2867 (parent #2503, source `P145`).
- Branch: `test/2867-v2-sidebar-localization-responsive-route-qa`.
- Integration target: **`Foodex_V2` ONLY**. No main merge, tag, release, production change or deploy.
- Scope owner: dedicated P145 test and this evidence document; no modifications to shared Sidebar, RBAC, routes or mobile application files.

## Source-to-surface map

| Domain | Entry point | Governing decision | Test evidence |
| --- | --- | --- | --- |
| Dashboard navigation | `AdminNavigation::groupsFor($user)` | Per-user effective role/permission, channel, tenant/store and module checks; presentation is never authority | Every visible first-level item has a registered named route with valid URL; no duplicate item keys, translated group labels |
| Wholesale | `admin.b2b.dashboard`, `admin.catalog.index` | `B2B_ADMIN` may view its own authorized Wholesale work | Authenticated 200 in both AR/EN |
| Security management | `admin.sidebar-access.roles`, `admin.sidebar-layout.index` | `roles.manage` required; absent permission means deny, even if a direct URL is supplied | Both 403 in AR/EN; corresponding links absent from rendered Sidebar |
| Channel fence | `admin.b2c.dashboard` | Wholesale role cannot gain Retail channel by Sidebar translation or responsive presentation | Direct HTTP 403 in AR/EN |
| Dashboard responsive navigation | `admin._sidebar` rendered within Wholesale workspace | Shared off-canvas drawer for <768px; controls/expanded states, Escape/Tab handling, background inert, logical RTL/LTR edge | Server-rendered template/script contract; requires browser runtime validation for actual geometry and focus |

The rendered navigation must use server-authorized route keys, *not* an independent localized allowlist. Mobile and desktop are two presentations of the same filtered Sidebar; no additional permissions may be granted at a breakpoint. The responsive/a11y source contract is verified against the actually rendered HTML, not an unrelated fixture.

## Regression command

From `backend/`: `php artisan test --filter=V2SidebarLocaleResponsiveRouteQaTest`.

Also run the repository's formatter/static-analysis and full required exact-head CI gate. A successful static markup assertion **does not prove** the drawer geometry, keyboard trap or no-overflow at all target viewports. Browser acceptance must check 320/360/390/412/430/768/1024/1280px for both RTL/LTR and keyboard focus before marking complete.

## Impact matrix

| Component | Effect | Explanation |
| --- | --- | --- |
| Backend | QA only | Existing HTTP permissions and route registry are exercised; no permission or API contract changed |
| Dashboard | QA only | Existing shared Sidebar markup and directions are inspected; no UI behavior changed |
| Customer app | Not affected | No customer API, Flutter route, authorization, translation or payload changed |
| Driver app | Not affected | No driver API, Flutter route, authorization, translation or payload changed |
| Van app | Not affected | No van API, Flutter route, authorization, translation or payload changed |

Separate mobile implementation Issues are unnecessary unless runtime/browser or later source tests uncover an actual mobile-app contract change.

## Outstanding acceptance

- Run focused tests, code formatter/static-analysis, repository policy and exact-head Required CI; record run URLs and pass/fail.
- Independently inspect responsive UI in a browser; screenshots alone are not functional focus, escape or overflow proof.
- If the test reveals missing translation, dead destination, incorrect denial, or focus defect, fix through the single Issue branch and revalidate before merge. Never weaken a failing gate.
- Leave the Issue open after integration until every P145 acceptance item is supported by verified evidence. No production confirmation is implied.

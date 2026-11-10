# V2 #2678 / P348 — Notifications in Administration

- Source: FOODEX Unified RBAC/ERP/Sidebar Discussion (2026-11-09) paragraph P348, parent #2503.
- Integration: `Foodex_V2` only; issue-scoped branch `fix/2678-v2-authorized-notifications-administration-nav`.
- Decision **Reuse**: existing Notifications Center and canonical `admin.notifications.index` (Laravel `backend/routes/notifications.php`); do not add a route, parallel notification module, new screen, or navigation permission.
- Placement: Administration > Notifications, using the existing `administration` Sidebar group. The existing Notifications view now marks `notifications_center` (not the unrelated Advertising campaign) as its active `navContext`, reflected by `aria-current="page"`. `notification_campaigns` remains Advertising and `admin.notifications.live` continues powering the live bell. The existing Administration Hub remains the normal control-plane entry point.
- Persona: authenticated `SUPER_ADMIN` with effective `notifications.manage`. `NotificationController::authorizeManage` independently requires both, and the Sidebar continues projecting exactly that server-authorized destination. Other roles, including B2B_ADMIN, and role-less users cannot view the link or load the management route.
- Channel/tenant/store: notification management remains the platform control plane; no B2B/B2C operational scope or notification audience change. Existing `NotificationController::assertScope` is the authority for store/channel targeting on writes; no new grants or unsafe cross-store access.
- No module/entitlement bootstrap, certification, queued delivery, audit mutation, data migration, or finance SoD change is introduced by this navigation-only work.

## Navigation/acceptance

- Domain=Administration; entity=notifications; capability=manage notices; navigation_type=SIDEBAR (existing authorized group) plus existing Administration Hub; canonical_route=`admin.notifications.index`; entry point=Management Dashboard authorized Sidebar; parent=Administration; pattern=Settings / Master Data management; fallback/back path=existing Administration Hub and Management Dashboard.
- Existing `notifications.title` translations remain authoritative for Arabic RTL and English LTR; no translation key introduced.
- Focused regression: `backend/tests/Feature/V2AdministrationNotificationsNavigationTest.php` checks AR/EN group placement, exact authorized named route, real HTTP 200 for Super Admin, real HTTP 403 and no Sidebar node for B2B role and role-less accounts. Existing notification management and cross-store delivery enforcement remains unchanged.
- No frontend CSS or responsive layout changes; separate physical-browser evidence is still required: 320/360/390/412/430/768/1024/1280px, keyboard/focus/a11y, AR RTL and EN LTR. Do not close #2678 until isolated V2 staging acceptance is attached.
- CI merge gate: exact-head Repository Policy, FOODEX V2 Isolation, PHP/Pint/PHPStan/security/OpenAPI, full Backend tests, localization AR/EN, MySQL/Redis setup/upgrade/recovery where applicable, required-ci-gate. No green inference from an earlier commit.

## Cross-surface impact

| Surface | Classification | Rationale |
| --- | --- | --- |
| Laravel Backend | Affected, read-only navigation projection | Same established permission/controller; changes placement only, no API or schema modifications |
| Dashboard | Affected, Sidebar navigation group | Administration becomes discoverable owner of Notifications; existing view/route/translation; no new screen |
| Customer Flutter | Not affected | No API, notification payload, deep-link, role, push subscription, or Flutter source changes |
| Driver Flutter | Not affected | No API, notification, GPS, task, or B2C Driver-only dispatch changes |
| Van Flutter | Not affected | No API, visit/order/push, or B2B Van-only dispatch changes |

Owner-locked restriction: no `main` merge, releases, tags, APKs or production deployment.

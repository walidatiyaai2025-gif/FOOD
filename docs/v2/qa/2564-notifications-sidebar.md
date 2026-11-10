# FOODEX V2 #2564 — Notifications Sidebar P234 authorization contract

**Source:** FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, P234 (Notifications). **Parent:** #2503. **Integration:** `Foodex_V2` ONLY. No `main` merge, production, release, tag or deployment.

## Placement and discoverability

| Dimension | Resolved authority |
|---|---|
| Domain / entity | Marketing & Advertising / promotional notification campaigns |
| Actor / goal | Authorized B2B/global or B2C store staff: discover, inspect and, with manage rights, maintain campaigns |
| Existing parent / action | Existing `AdminNavigation::groupsFor` > Marketing/Advertising `notification_campaigns` |
| Navigation | Sidebar, existing `admin.notification-campaigns.index` route (`GET /admin/notification-campaigns`) |
| Placement | **Reuse** existing Laravel campaign index, records, review workflow; no second notification page or Sidebar entry |
| Permission | Index `notifications.view`; create/update/activate/pause/send `notifications.manage`; independent Super Admin notification center remains separate |
| Scope / module | Actual tenant/store/channel grants; active B2C store with `advertising_enabled`; no store data leakage; B2B controls unchanged |
| Readiness | Existing campaign Dashboard controller and endpoints are live; do not expose a nonfunctional link |
| Pattern | Existing responsive shared Dashboard shell; same route in desktop/tablet/mobile-web; shared locale keys and focus behaviors |
| States | Empty/results/filtered/read-only; forbidden direct HTTP on missing entitlement; read-only blocks review and mutation widgets |
| Back/fallback | Existing Marketing/Advertising Sidebar group and normal Dashboard global header |

**Security:** Sidebar advertising-item projection is based on `notifications.view`. Index must not demand `notifications.manage`, or a view-only actor receives a misleading 403. Mutating endpoints still demand `notifications.manage`; read-only cannot open send-now/activation audience review or see write forms. Retail record queries remain scoped, and foreign-store mutations remain blocked. Notification center `admin.notifications.index` retains Super Admin-only server authority.

## Acceptance and automation

- `backend/tests/Feature/V2NotificationsSidebarAuthorityTest.php` covers existing link/real route for authorized retail actor, both `ar` and `en`, view-only reading of permitted store records, absent cross-store records, read-only no write forms/review, forbidden direct mutations, disabled advertising negative behavior, and central admin center role denial/allow.
- Existing `PromotionalNotificationCampaignTest`, `AdvertisingCouponsAndMobileTrialTest` and Laravel feature tests protect campaign mutations and store ownership; use exact-head CI for PHP format, Laravel, security, AR/EN, package/deployment acceptance and V2 isolation.
- Locale labels already use `notifications.*` translations, with existing RTL/LTR shared Dashboard shell and Mobile Web responsive patterns. No new route, key, script, CSS, menu node or mobile UI primitives.
- **Data:** no schema migration, destructive operation, authentication grant, seed mutation or production change.
- **Customer App:** no API, notification schema, recipient selection, deep-link or Flutter source change. Existing Customer notification delivery unaffected.
- **Driver App:** no API, notification schema, dispatch or Flutter source change. Existing Driver notifications unaffected.
- **Van App:** no API, notification schema, VAN ONLY B2B fulfillment or Flutter source change. Existing Van notifications unaffected.
- **Manual acceptance:** deferred to final #2503 / UX gate #2750 per owner decision on #2503. Do not misrepresent deferred browser/device staging UAT as completed.

**Implementation branch:** `test/2564-v2-notifications-sidebar-authority`. **PR and exact-head CI:** record live evidence in Issue/PR before merge and closure.

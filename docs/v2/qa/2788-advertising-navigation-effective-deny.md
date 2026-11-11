# V2 #2788 / T037R02 — Effective-authorizer Sidebar projection

**Isolated target:** `Foodex_V2` only. Source: Unified RBAC / ERP / Sidebar, T037R02; parent #2503. This issue follows the explicit incomplete-navigation dependencies recorded by #2564 (notifications) and #2563 (coupons).

## Canonical authority and access

- **Reuse, not a new screen:** Existing `AdminNavigation::routeItemAdvertising` and the existing routes `admin.notification-campaigns.index`, `admin.live-ads.index`, and `admin.coupons.index`. Their current business-group placement, AR/EN labels, RTL/LTR and responsive shell remain unchanged; any target IA regrouping must be source-approved separately, without adding an unauthorized route.
- **Actual actor:** Assigned active Retail B2C operator with an active store and an effective view permission. For notifications use `notifications.view` + `advertising_enabled`; Live Ads uses `live_ads.view` + `live_ads_enabled`; Coupons uses `coupons.view` + `coupons_enabled`.
- **Decision authority:** `OperationalTenantScope::allowedStoreIds(user, permission, 'b2c')`, intersected with `TenantContextResolver::retailStoreIds(user)`, then checked against actual active stores and each feature flag. This evaluates per-user global STORE/ALL DENYs and valid store allowances. A raw `user_store_roles -> permissions` candidate grant is not a final authorization decision.
- **Global authorized actors:** Preserve the pre-existing Super Admin/true global-permission path where real HTTP endpoints permit it. No store permission, role, entitlement or runtime module is newly granted.
- **Failures:** A viewer whose sole authorized store is denied or feature-off has no link. HTTP authorization remains an independent requirement; notifications already deny the same effective scope with 403. Coupon HTTP DENY is tracked, implemented and tested in original #2563 / PR #3076 but **not yet integrated** into this branch; do not claim the current coupon controller denies an inherited store-role grant until #2563 merges. When one of two assigned stores is denied but the other remains genuinely allowed, a single link still exists.
- **Safety:** No permission-table mutation, business data change, migration, new route, silent change to audit/finance/dispatch, no legacy role-projection override, no unreviewed ERP readiness shortcut. Three-level Sidebar depth and existing group registration remain authoritative.

## Regression tests

`backend/tests/Feature/V2AdvertisingNavigationEffectiveDenyTest.php` checks:
1. Real assigned Retail role grants in a disposable test database, then explicit global DENY for notifications, live ads and coupons; all three links vanish in Arabic and English, while the already-integrated notification GET returns 403. Coupon HTTP denial remains an explicit #2563 dependency.
2. One of two authorized stores denied: navigation remains via the permitted store, with genuine notification GET 200; after denying both, every link disappears and the real notification GET returns 403 (coupon controller parity is #2563).
3. Each feature disabled and roleless actor: no misleading advertising links; direct HTTP denied where applicable.
4. Existing `AdvertisingCouponsAndMobileTrialTest` and `V2NotificationsSidebarAuthorityTest` retain feature/route, mutation authority and AR/EN contracts. No weakened negative test is permitted.

## Per-application impact matrix

| Surface | Impact |
| --- | --- |
| Backend / Dashboard | Affected: existing Sidebar visibility now reflects effective per-user authorization; protected HTTP action permissions remain as-is |
| Customer Flutter | Not affected: no customer API, quote/checkout coupon, auth or response schema changes |
| Driver Flutter | Not affected: no delivery, GPS, wallet, status or public API changes; B2C DRIVER ONLY unchanged |
| Van Flutter | Not affected: no visit, routing, pricing or public API changes; B2B VAN ONLY unchanged |

**Acceptance gate:** exact tested source SHA must pass Repository Policy, FOODEX V2 Isolation Gate and Required CI Gate, including Laravel, Pint/PHPStan/security/OpenAPI, AR/EN and MySQL/Redis fresh-install/upgrade/recovery; PR to `Foodex_V2` only. Manual browser/device/complete target IA audit remains `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` under #2503/#2750; this does not waive code/CI evidence. Close #2788 only after verified safe merge; separately revalidate original #2563/#2564 dependencies without duplicate branches.

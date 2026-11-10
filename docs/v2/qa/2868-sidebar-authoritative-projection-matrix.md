# V2 P156 — Sidebar authority projection matrix

- **Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P156, parent #2503.
- **Integration:** exclusively `Foodex_V2`, one issue-scoped branch/PR. No main, release, deploy or production.
- **Implementation:** reuse existing `AdminNavigation`, `TenantContextResolver`, `User::hasPermission`, `EnsureManagementDashboardAccess`, `EnsureErpOperationalReadiness`, `ModuleOperationalReadiness` and `ModuleEntitlementResolver`. No new page, navigation entry, middleware, authorization or UI policy.
- **Placement:** existing business-domain Sidebar + existing Dashboard page links; test-only change. The Sidebar projects the real server policy and cannot grant access.

## Exact actors, routes and scopes

| Persona | Entry / HTTP route | Authority and data scope |
| --- | --- | --- |
| Global B2B_ADMIN | `catalog_management` → `admin.catalog.index` | `catalog.view`, wholesale/global channel only; route must return 200 for entitled user |
| Mixed B2B_ADMIN global + RETAIL_INVENTORY at store A | Both wholesale catalog and scoped Retail inventory | One actor has both channel contexts; each authorized direct HTTP surface succeeds and foreign Retail store still fails 403/404 |
| RETAIL_INVENTORY at store A | `b2c_inventory` → `admin.b2c.module` (`inventory`, store A) | store-scoped `inventory.view`; A returns 200, foreign B returns 403/404, B2B inventory returns 403 |
| Global B2B_ADMIN with explicit per-user DENY | Forced-visible `catalog_management` and direct `admin.catalog.index` | the persisted DENY overrides inherited `catalog.view`; link omitted and direct URL 403 even with visual flag true |
| SUPER_ADMIN with ERP procurement | `erp_procurement` → `admin.erp.procurement.index` | module lifecycle ENABLED, platform entitlement and exact-version operational readiness required for discovery; uncertified/stale readiness produces direct HTTP 403 |

Every case runs with Arabic and English users; the successful Dashboard responses verify corresponding RTL/LTR direction. Synthetic users and disposable stores exist only inside `RefreshDatabase`; no live accounts, financial rows or production settings are touched.

## Acceptance and security evidence

`backend/tests/Feature/V2SidebarAuthorityMatrixTest.php` is the executable P156 combined contract. It covers real permission, active role and channel, two Retail stores, a dual-channel multi-role actor and cross-store rejection, forged visible role layout vs effective DENY, direct protected HTTP, authorized responses, readiness certification, stale module version, physically disabled module with direct HTTP 403, entitlement revocation and audit preservation. It supplements rather than rewrites separately owned P035/P106 and active per-page PRs.

The test deliberately does not assert that a module entitlement *alone* grants direct HTTP access. The ERP route retains its own server-side role/tenant guards, and the operational readiness middleware independently rejects uncertified routes. Financial maker/checker, commercial ledger restrictions and the remaining product journeys are exercised by their dedicated V2 security/ERP issues; this focused matrix neither changes nor weakens them.

## Customer / Driver / Van impacts

| Application | Classification | Evidence |
| --- | --- | --- |
| Backend/API | Backend automated tests only | Existing Dashboard URLs, Gates, model policy and middleware are exercised, not changed. No API v1 route or response contract modified |
| Dashboard | Backend Feature regression only | Existing Sidebar and business pages reused; no view, CSS or JS changes |
| Customer | Not affected | No Customer Flutter, auth/API payload, B2B/B2C shopping, order or credit contract changes |
| Driver | Not affected | No Driver Flutter, B2C-only dispatch, assignment, delivery or GPS changes |
| Van | Not affected | No Van Flutter, B2B-only routing, visits, assignments or finance collection changes |

## Required delivery and final acceptance

Before merge: exact-head Repository Policy, FOODEX V2 Isolation, Laravel/Pint/PHPStan/security/OpenAPI, AR/EN and required MySQL/Redis installation/upgrade/recovery acceptance plus terminal `required-ci-gate` SUCCESS. Check real CI child jobs, not top-level appearance.

**MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL**, per owner's written #2503 override dated 2026-11-10. Final consolidated V2 staged browser/device responsive/a11y/UAT is tracked on #2503, UX #2750 and release/build #2749, not presumed by a CI result. This deferral does not waive real code acceptance or exact-head CI. Any unmet requirement remains open and must be fixed before marking the implementation complete.

No main integration, production artifacts, release tags, deployments, migrations, runtime changes or claims of live readiness.

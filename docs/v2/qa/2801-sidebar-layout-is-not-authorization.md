# V2 #2801 / P050 — Sidebar layout is not authorization

**Source:** FOODEX Unified RBAC/ERP/Sidebar discussion (2026-11-09), P050. Parent #2503. Integration: `Foodex_V2` only.

## Canonical boundaries

- **Domain / entity / role / goal:** Dashboard navigation presentation; an authenticated business actor can organize already-authorized destinations, not change effective permissions.
- **Placement:** Reuse the existing `admin.sidebar-layout.index` and `admin.sidebar-access.roles` settings surfaces, without new navigation or routes. Existing pattern is Settings/Master Data; RTL/LTR responsive handling is unchanged.
- **Authority:** `User::hasPermission`, role scope, `OperationalTenantScope`, module readiness/entitlements, route authorization and tenant guards decide access. `AdminNavigation::groupsFor` creates the authorized tree; `AdminSidebarManager::apply` only filters, sorts, nests and relabels existing authorized links. Never treat a group, layout enable flag, role visibility row or menu item as an access-control grant.
- **HTTP contract:** `admin.catalog.index` requires the real `catalog.view` authority, `admin.sidebar-access.roles` requires `roles.manage`, and `admin.b2c.module` enforces channel/store boundaries. Layout changes do not modify this contract.

## Scoped tests

`backend/tests/Feature/V2SidebarLayoutAuthorizationSeparationTest.php` covers:

1. For a seeded, active wholesale B2B_ADMIN actor, hide `catalog_management` through the canonical layout service: its destination disappears, but effective `catalog.view` and direct authenticated GET to Catalog remain valid.
2. Restore and regroup that authorized link under Analytics: it appears in the new business group and still uses the original protected route. Verify original role grants and per-user override absence are preserved.
3. Enable and request role visibility for forbidden `sidebar_role_access` and B2C inventory keys: a wholesale actor must still lack `roles.manage`, show neither forbidden link, receive HTTP 403 on direct role-access route, and receive 403/404 on attempted retail store route. A roleless actor cannot gain Catalog by layout and receives HTTP 403.
4. Exercise AR/RTL and EN/LTR for the applicable routes. Fixture is synthetic; no production users, roles, stores or financial data are modified.

This test is intentionally different from #2830 (rejecting group IDs as page permissions) and #2902 (read-only canonical Sidebar metadata): it checks **persisted presentation changes versus direct HTTP route access** within the same interaction sequence.

## Impact / acceptance boundary

- **Backend / Dashboard:** new regression test only; no production controllers, authorization services, database migrations, navigation entries or UI markup changed. Existing routes, keyboard/accessibility semantics, audit flow, entitlements, financial SoD and permission guards remain authoritative.
- **Customer app:** not affected; no Flutter/API/auth/account state change.
- **Driver app:** not affected; B2C DRIVER ONLY dispatch and driver permissions unchanged.
- **Van app:** not affected; B2B VAN ONLY routing and van permissions unchanged.
- **Release & hosting:** no deployment, setup/update ZIP, production artifact or main branch operation.

**Evidence status:** source-level inspection of existing services and prior tests performed. Execute the new Feature test plus Pint/PHPStan/required CI on the PR exact head. Per the OWNER OVERRIDE in master #2503 (2026-11-10), close this leaf Issue as completed only after the scoped implementation is merged into Foodex_V2 and all exact-head required CI and applicable security/localization/impact checks pass. Record MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL; independent staging browser, role/store/account, AR/EN and responsive/accessibility/UAT acceptance remain centrally tracked on #2503 (and #2750/#2749 where relevant), not a leaf-closure blocker.

# V2 T028R04 — No independent role-visibility rule

- Source: FOODEX Unified RBAC/ERP/Sidebar (2026-11-09), Table T028, row R04; Issue #2781; parent #2503.
- Integration: **Foodex_V2 only**. This is an authorization regression/verification task, not a new page, route, permission, or role-visibility policy.
- Canonical decisions: `AdminNavigation::groupsFor` enumerates only authorized items through effective user permission, channel, module/readiness and tenant scope; `AdminSidebarManager::apply` then filters/reorders the authorized set. Absent records in `admin_sidebar_role_visibility` mean no additional presentation preference, never an implicit grant or deny.
- Actors: wholesale `B2B_ADMIN` (global) and `RETAIL_INVENTORY` (one assigned Retail store); entry points: existing catalog, role management and B2B/B2C module routes. No secondary security predicate in the Sidebar.
- Test: `backend/tests/Feature/V2SidebarNoIndependentRoleVisibilityTest.php` deletes role-visibility records, then validates the authorized B2B catalog remains discoverable and HTTP 200; role management and B2C direct routes remain HTTP 403. An authorized Retail inventory item stays available without independent visibility preferences, while cross-channel B2B access and foreign-store inventory are denied (403/404) in Arabic and English.
- Presentation: verifies `dir=rtl` and `dir=ltr` on the existing authorized B2B page, without changing components, labels, or focus behavior. Pre-existing responsive/accessibility, role DENY, module/entitlement/readiness and SoD regression suites remain authoritative and must pass in Required CI; no standalone browser/staging acceptance is claimed by this test.
- Data safety: tests use `RefreshDatabase` and seeded synthetic users/stores only; no live data, permission records or financial state modified.
- Customer app: NOT AFFECTED — no API, authentication, pricing, customer scope or Flutter changes.
- Driver app: NOT AFFECTED — no dispatch, delivery, GPS, authentication or Flutter changes.
- Van app: NOT AFFECTED — no visit, wholesale fulfillment, finance, authentication or Flutter changes.
- Acceptance: exact-head Repository Policy, FOODEX V2 Isolation Gate, Required CI (Laravel, Pint, PHPStan, security, AR/EN, MySQL/Redis fresh-install/upgrade/recovery) must all be successful before a PR to `Foodex_V2` is merged. Leave #2781 open if real staging browser/role acceptance is not independently evidenced. Never integrate with `main` or deploy.

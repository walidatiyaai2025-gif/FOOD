# V2 #2642 — P312 BI Sidebar / HTTP authorization parity

## Canonical route and placement

Source: FOODEX unified RBAC/ERP/Sidebar P312; parent #2503. Domain: Reports & BI. Entity: registered enterprise report definition. Persona: active global reporting actor (including BUSINESS_ANALYST and authorized administrators). User goal: access and run genuinely entitled reports.

Placement: **Reuse** existing Analytics → BI item (key erp_bi), route admin.erp.bi, EnterpriseBiController::index, and admin.enterprise-bi renderer. The ERP Center BI card points to the same canonical route. Navigation type: SIDEBAR / DOMAIN_HUB. Navigation owner: AdminNavigation. Screen pattern: Data Explorer / reporting workbench; authorized back paths: Reports Center and ERP Center. No new screen, menu, duplicate renderer or route. Existing Dashboard desktop/mobile responsive composition and Arabic RTL / English LTR are unchanged.

## Root cause and security correction

EnsureErpOperationalReadiness already protects admin.erp.bi and schedule routes using exact manifest-version certification, active module lifecycle and health. Sidebar additionally verifies reports.view, active global role and a valid platform or assigned retail-store enterprise_bi entitlement. However the BI index HTTP handler previously checked only active user plus reports.view, so an unentitled user could directly load a hidden BI page.

Now index enforces the same platform-role/profile boundary and effective enterprise_bi entitlement as the Sidebar **before** rendering report metadata/workspace data. Entitlement is from platform scope or an active authorized retail store in TenantContextResolver::retailStoreIds, not a foreign store. The existing authenticated Platform Owner exception remains; report-level ReportAccessResolver and execution authorization remain independent. No readiness check, permission grant or new reporting engine introduced.

## Automated tests

New backend/tests/Feature/V2BiSidebarReadinessAuthorityTest.php exercises:

1. Entitled assigned-store BUSINESS_ANALYST: BI Sidebar route and authorized HTTP page in Arabic RTL and English LTR.
2. Foreign-store-only entitlement: hidden Sidebar and HTTP 403; adding actual assigned-store entitlement restores access.
3. Enabled and ready but no entitlement: deny even SUPER_ADMIN, then validate platform-scope grant.
4. Invalid exact-version readiness and disabled lifecycle: fail closed on navigation and direct GET.
5. Multi-role actor with explicit reporting DENY and roleless actor with a platform-entitled module: HTTP 403 and no BI navigation.

Tests use seeded real RBAC, active B2C store hierarchy and module entitlement resolution. They never rely on Sidebar visibility as the actual security gate.

## Affected surfaces and constraints

- Backend: BI Dashboard HTTP controller only; no public/mobile API contract.
- Dashboard: existing BI navigation and data explorer; no CSS, HTML, routes or new workbench; real route now has matching authorization.
- Customer Flutter: no impact — no changed endpoint, data schema, authentication, cart, orders or navigation.
- Driver Flutter: no impact — no changed dispatch/delivery, payment or location contract.
- Van Flutter: no impact — no changed B2B visit/routing/assignment or stock contract.

Only Foodex_V2 PR after latest exact-head Required CI, Repository Policy, V2 Isolation and children pass. No main, release, tag, staging or production. Manual UX/UAT is deferred to master #2503 / gate #2750 (MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL), not claimed here.

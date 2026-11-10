# FOODEX V2 P183 / Issue #2513 — Restaurant digital ordering Sidebar entry

Source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09, Part 2 section 4, P183. Parent #2503. The PR merge base is Foodex_V2 only.

## Business placement, role and route

- Domain/entity: B2C restaurant operations, existing Smart Ordering Site and real operational workspace.
- Actor and goal: the active admin of an enabled restaurant store opens its configured restaurant site and its existing operations/menu/QR/analytics tabs, without acquiring a global or wholesale entitlement.
- Reuse-first decision: the existing canonical restaurant entry in the Stores business domain, AdminNavigation::restaurantSaasItem, key smart_ordering, label smart_ordering.nav_title, canonical route admin.smart-ordering.index (GET /admin/smart-ordering/settings). The workspace's subpages are its existing child functions. Do not manufacture another Overview page, extra duplicate QR item, second Sidebar or unprotected link.
- Placement discrepancy requiring explicit product acceptance: P183's source tree says Overview, but the current business-domain implementation places this unique entry under Stores. The authored test preserves that current canonical behavior without approving a change to the source IA; active worker #2869 owns the shared AdminNavigation source. Independent product review must decide if repositioning is required before #2513 may be fully closed.
- True server authority: current AdminNavigation requires permitted B2C channel, an assigned active restaurant-profile store with Restaurant SaaS entitlement enabled, and effective scoped permission to one of the real restaurant routes. The server independently enforces RestaurantSaasStoreAccess, tenant resolver and stored entitlements, not the Sidebar visibility or a role's name alone.
- Authorized: enabled B2C restaurant manager reaches the actual Smart Ordering settings view with HTTP 200. Foreign store returns 404. Ordinary B2C nonrestaurant profile or disabled Restaurant SaaS returns 403, and neither has the entry. B2B-only actor cannot see or open restaurant workspace. Super Admin's daily business Sidebar does not expose a restaurant operator link; support access uses its separately audited flow.
- Labels: Arabic الطلب الذكي (RTL) and English Smart Ordering (LTR) use one existing translation key. Readiness: enabled store entitlement does not falsely imply POS, kitchen, purchasing or onboarding launch readiness. Existing per-module/onboarding hard gates and disabled, empty, error handling remain authoritative.
- Back/fallback: return to the assigned retail store Dashboard or Stores business domain; unauthorized direct URLs fail closed rather than opening unrelated channel content.

## Verification and acceptance

The new test backend/tests/Feature/V2RestaurantOverviewEntryAuthorityTest.php validates: one restaurant Sidebar entry under both locales, exact real route and rendered view, no duplicate QR or analytics menu shortcuts; assigned enabled store 200; unrelated restaurant store 404; ordinary retail and unenabled restaurant denied 403; B2B_ADMIN and SUPER_ADMIN have no implicit restaurant operator Sidebar entry, with B2B direct route denied. Existing RestaurantSaasNavigationConvergenceTest, RestaurantSaasRetailStoreEntitlementTest and RestaurantOnboardingTest continue to cover module readiness, lifecycle and audit.

Before integrating, require all exact PR-head checks: Repository Policy, FOODEX V2 Isolation Gate, full Laravel tests, PHP syntax/Pint/PHPStan, Composer audit, AR/EN quality, MySQL Redis fresh install/upgrade/recovery and final required-ci-gate. No runtime sources, schemas, business data or migration are changed.

Independent visual/interaction acceptance is NOT claimed. Check actual isolated-V2 staging AR/EN restaurant workspace on 320, 360, 390, 412, 430, 768, 1024 and 1280px with keyboard focus, Escape, mobile drawer, long restaurant names, feature on/off, store switching, error/empty/loading states, and formal business-domain placement decision. Real functional evidence is mandatory; screenshot-only workflows were retired. Keep #2513 open until all remaining acceptance passes.

## Backend/Dashboard/Customer/Driver/Van impact

- Backend: only a Laravel Feature regression test, no production API/controller/route/policy/entitlement change.
- Dashboard: canonical Sidebar and Smart Ordering workspace reused, no Blade/CSS/JavaScript source change.
- Customer App: not affected; no storefront payload, checkout API or Flutter changes.
- Driver App: not affected; no driver assignment, route, delivery lifecycle, API or Flutter changes. B2C remains DRIVER ONLY.
- Van App: not affected; no B2B visit, VAN dispatch, inventory, API or Flutter changes. B2B remains VAN ONLY.
- Security: store tenant separation, accounting maker/checker and user preferences unchanged.

No merge to main, no production deployment, release/tag, data mutation, cPanel installation or deletion of active branches. Parent #2503 stays open.

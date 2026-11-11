# V2 #2711 — Customer Service persona authorized journey (T353R2)

**Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, T353R2; parent #2503. **Integration:** `Foodex_V2` exclusively.

## Scope and placement

Existing built-in `CUSTOMER_SERVICE_MANAGER` is the canonical role, not a new persona or independent Sidebar grant. Reuse → Extend → Compose → New Screen decision: **REUSE** its existing Dashboard Customer Service workspace, Customer 360, Operations Orders and Reports destinations. The existing WhatsApp Inbox is shown only when the WhatsApp `RuntimePolicy::allowsAgentInbox()` is true and the actor holds an effective `whatsapp.inbox.view` permission.

Canonical navigation/group contract: Overview → `erp_customer_service` (`admin.erp.customer-service`), Customers → `customer_360` (`admin.customer-360.index`), Orders → `order_operations` (`admin.operations.orders.index?channel=b2b`), Analytics/Reports → `reports_center` (`admin.reports.index`). Service/WhatsApp Inbox uses `admin.whatsapp-inbox.index` when enabled and authorized; all relevant controller and operational tenant checks remain authoritative. No new Sidebar link or duplicated route/screen is introduced.

The existing Customer Service Manager role may also have authorized Catalog/Advertising destinations under its pre-existing built-in permission bundle. T353R2's target list is a required cross-domain path, **not authority to revoke separate permissions** or to hide unrelated actions independently from HTTP authorization.

## Executable regression

`backend/tests/Feature/V2CustomerServicePersonaAuthorizedJourneyTest.php` verifies:

- Global Customer Service Manager has B2B (Wholesale) operational scope, not B2C store scope, for actual order queries.
- Real, navigable, non-duplicated Customer Service, Customer 360, Wholesale Orders and Reporting paths in AR/RTL and EN/LTR; HTTP 200 and expected page direction.
- Existing WhatsApp Inbox visibility follows the module runtime policy AND the effective permission. A disabled runtime is not silently activated by the assigned role.
- Customer-service presentation excludes Super Admin/ERP control-plane, Live Driver Tracking and Retail order links, while authorized service routes remain.
- Direct Retail Orders, Retail Customer module and Security administration routes are denied; Finance approval and platform administration permissions are absent.
- Explicit per-user `reports.view`, `whatsapp.inbox.view`, and `whatsapp.conversations.view` DENYs remove Sidebar links; reporting HTTP returns 403, while WhatsApp Inbox HTTP returns 403 if the runtime is operational or 404 when the existing capability middleware hides the disabled module. Unrelated Customer Service and Wholesale Orders remain authorized.
- Roleless and inactive users cannot reach real service/order/reporting HTTP endpoints.

Only synthetic test identities and disposable CI test data are used. No existing role definitions, user grants, live records, migrations, OpenAPI, routes, Dashboard Blade, app source or production server state are modified.

## Backend / Dashboard / app impacts

| Component | Classification | Evidence |
|---|---|---|
| Backend + Dashboard | Regression evidence (no runtime change) | Tests exercise existing controllers, tenant scope, built-in role, live permissions, Sidebar projection and AR/EN markup |
| Customer App | Not affected | No customer authentication/API, catalog/pricing, checkout, order status, notification schema, Flutter changes |
| Driver App | Not affected | No Driver identity or assignment/dispatch/status/GPS API or Flutter changes; B2C=DRIVER ONLY preserved |
| Van App | Not affected | No Van login/territory/consignment/route/assignment/API or Flutter changes; B2B=VAN ONLY preserved |

No mobile follow-up issue required for test/docs-only additions. Any future shared API, permission or dispatch behavior change must separately track and test each affected app.

## Acceptance and gates

Run focused Laravel Feature tests and exact-head full Backend/PHP syntax/Pint/PHPStan/security/OpenAPI, bilingual localization, Repository Policy, FOODEX V2 Isolation, FOODEX Required CI and MySQL/Redis install/upgrade/recovery. Record run URLs, exact tested head and merge proof in the Issue; fix any CI failure on this same branch/PR.

Cross-persona live UAT, screen interactions/accessibility on devices, cross-store production-account data and the exhaustive master V2 audit are not certified by this focused regression alone; defer manual staging/UAT only as instructed in owner decision on #2503, while treating any other missing source criterion as still open. No main merge, release/tag or production/cPanel deployment.

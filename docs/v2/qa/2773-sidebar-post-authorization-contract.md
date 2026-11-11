# FOODEX V2 #2773 — P022 post-authorization Sidebar contract

Source: FOODEX Unified RBAC/ERP/Sidebar discussion (2026-11-09), P022; master #2503. Isolated merge target: **Foodex_V2 only**; no main promotion, production deployment, release or tag.

## Scope and canonical authority

- **Role and entry point:** Super Admin uses the existing Administration → Sidebar Role Access form; an authorized B2B operator opens the existing Catalog Management route.
- **Placement:** Reuse the current role editor and catalog page. No second permission system, new page, Sidebar destination or endpoint is needed.
- **Authorization before appearance:** AdminNavigation resolves the real server-side role/permission, store, channel, module/entitlement and readiness decisions; only its resulting candidate navigation passes to AdminSidebarManager for cosmetic visibility, localization, grouping and ordering. Sidebar flags are not access-control data.
- **HTTP boundary:** Existing role-editor GET/PUT requires roles.manage; the target Catalog GET requires catalog.view and its existing server scope. Neither visibility state nor a layout editor checkbox grants or revokes real HTTP access.

## Executable regression and failure paths

Dedicated Feature test: backend/tests/Feature/V2AdminSidebarPostAuthorizationContractTest.php.

1. An authorized Super Admin submits the actual Sidebar role visibility editor hiding Catalog. The B2B user's Catalog menu entry disappears, but the granted Role-Permission pivot stays identical and direct authorized HTTP access remains HTTP 200 in Arabic RTL and English LTR.
2. The role-display mutation creates its existing audit event; it does not modify Role-Permission grants or business data.
3. A submitted group key masquerading as an item fails validation atomically and produces no visibility/audit write.
4. A roleless user cannot read or write the protected role editor (HTTP 403), and cannot create hidden/visible rows.

The implementation already separated the authoritative navigation projection from its presentation layer. This change deliberately adds nonduplicative **HTTP writer** coverage instead of editing a second authorization policy. No production code was changed.

## Surface impact assessment

| Surface | Impact |
| --- | --- |
| Backend / Dashboard | Only additive Laravel HTTP security/AR-EN regression; existing UI, role editor and route reused. No new API or authorization grant. |
| Customer | Not affected: no API/login/cart/order/notification/runtime change and no Flutter source change. |
| Driver | Not affected: no assignment/dispatch/delivery API or Flutter change. Retail B2C → Driver-only stays invariant. |
| Van | Not affected: no van assignment/route/visits/dispatch API or Flutter change. Wholesale B2B → Van-only stays invariant. |

No linked mobile Issue is appropriate absent a changed mobile contract. Required exact-head CI must verify Laravel, Pint/PHPStan, security, AR/EN, MySQL/Redis install-upgrade-recovery, Repository Policy and V2 Isolation before the PR is merged. Manual staging/UAT is deferred under the parent owner's 2026-11-10 decision; automated code evidence does not establish production-instance validation.

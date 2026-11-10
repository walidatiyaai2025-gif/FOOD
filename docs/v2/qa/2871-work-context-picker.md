# V2 #2871 / P160 — Authorized work-context selector

Source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09, P160. Parent: #2503. Exclusive integration target Foodex_V2. No main, production, release or tags.

## Placement, business flow and route authority

- Domain: Dashboard workspaces. Entity: currently authorized workspace. Actor: active authenticated Platform/Wholesale/Retail/Restaurant/Analytics operator.
- Goal: reach an already permitted business context through the existing Sidebar. Workflow is frequent, read-only navigation; five possible links, fewer for restricted actors. No data or financial mutation.
- Placement decision: REUSE → EXTEND canonical admin._sidebar via existing AdminNavigation::groupsFor and one small contextual partial. No new page, Sidebar domain group, controller, route or permission policy.
- Navigation: SIDEBAR, existing Business Sidebar below search, using native details/summary disclosure and accessible anchor links. Browser Back and existing Sidebar remain the fallback. The element is entirely hidden if none of the final authorized links exists.
- Responsive and a11y: inherited mobile drawer and AR RTL / EN LTR layout; localized labels, 44px click targets and visible focus; no JS navigation, local storage, request state or new auth bypass. Live browser acceptance remains outstanding.

## Single-source mapping

Only final already-authorized Sidebar items may be used, with their exact original route name and parameters (including tenant/store):

| Context | Existing candidate item keys, priority |
| --- | --- |
| Platform | enterprise_workspaces, administration_hub |
| Wholesale | b2b_dashboard |
| Retail | b2c_dashboard |
| Restaurant | smart_ordering |
| Analytics | reports_center, b2c_reports, b2b_reports, erp_bi |

V2SidebarContextLinks projects these already-rendered server-authorized items; it does NOT decide permission, entitlement, tenant, channel or module readiness. It requires a registered route. An absent/hidden module yields no link. Restaurant only appears after existing Smart Ordering restaurant store, SaaS entitlement and permission checks supply its item. There is no input for an arbitrary store ID and no client-created destination. Existing HTTP guards remain authoritative (403 for forbidden channel/role, 404 for foreign store).

## Regression coverage

The new V2SidebarWorkContextPickerTest verifies AR/EN and RTL/LTR B2B and B2C HTTP, exact links derived from final AdminNavigation, scoped retail destination, absent wholesale links for store-only roles, denied B2B route (403), denied foreign-store route (404), unknown/fabricated context omission, platform/wholesale projections for authorized Super Admin, and roleless/inactive denial with no Sidebar context markup. Audit is read-only, no changes to permissions/roles/API/migrations; financial maker/checker and B2B VAN ONLY/B2C DRIVER ONLY unchanged.

## Per-application impact

- Dashboard: affects canonical Sidebar navigation only, no API or data writes.
- Customer: NOT AFFECTED — no Flutter/API/auth/pricing/cart/deeplink changes.
- Driver: NOT AFFECTED — no Flutter/API/dispatch/status/GPS changes.
- Van: NOT AFFECTED — no Flutter/API/routes/visits/settlements changes.
- No new mobile issue is warranted for this Dashboard-only navigation projection.

## Definition of done and acceptance boundary

One issue-scoped branch, one PR to Foodex_V2; Repository Policy, FOODEX V2 Isolation Gate, Required CI and required children (PHP syntax, Pint, PHPStan, security, OpenAPI, AR/EN, full Laravel and real MySQL/Redis fresh-install/upgrade/recovery) all must be green on exact final HEAD before isolated merge. Rollback is a source revert of this read-only change; no database reversal.

Keep Issue #2871 OPEN if exact isolated staging browser validation remains outstanding. Independent acceptance must cover restaurant entitlement on/off and actual authorized user, selector navigation vs direct HTTP, 320/360/390/412/430/768/1024/1280px AR RTL and EN LTR, keyboard Tab/Space/Enter, visible focus, mobile drawer, zoom/long labels, screen reader and no overflow. Never claim this occurred based on static tests or CI alone. Never main or production without new owner approval.

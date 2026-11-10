# V2 #2756 · P011 — Sidebar Layout presentation-only HTTP boundary

Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P011. Parent #2503. Integration branch: `Foodex_V2` only.

## Authoritative placement and permissions

Existing route `admin.sidebar-layout.index` and `admin.sidebar-layout.update` (Administration > Sidebar Layout) are reused; no new screen, route, button, menu, endpoint, app contract or permission is introduced. The existing Desktop/Tablet/Mobile Web responsive surface remains authoritative; no duplicate UI or navigation. Gate `roles.manage` protects GET/PUT. The canonical Sidebar registry owns item routes and their permission, channel, scope and module metadata. `admin_sidebar_groups` / `admin_sidebar_items` store display preferences (group, parent, localized labels, icon, order and legacy visible toggle), never canonical grants. Enabling a presentation flag is not equivalent to granting protected HTTP access.

## Source-scope regression

`backend/tests/Feature/V2SidebarLayoutPresentationWhitelistTest.php` exercises a *real* authorized HTTP PUT with AR/EN group and item labels, group icon, group arrangement and page order. A deliberately tampered nested payload also contains `permissions`, `roles`, `scope` and `route`; it must not alter registry authority, persisted user-role assignments or role-permission links. The successful change must emit the existing audit event. Both Arabic RTL and English LTR GET rendering remain accessible.

Unknown Sidebar item keys must fail atomically, without any item/group/audit write. A roleless user cannot PUT or GET the Layout editor (403); this form is not a role editor. Existing `AdminSidebarManagementTest` and concurrent #2801 cover complementary navigation/HTTP separation; this issue intentionally does **not** edit `AdminSidebarManager.php` or shared routes, which are fenced by concurrent #2780.

## Scope / cross-app and security classification

| Surface | Impact |
| --- | --- |
| Laravel Backend | New feature regression only; existing controller, service, schema and authorization unchanged |
| Dashboard | Existing Sidebar Layout UI, AR/EN and RTL/LTR verified at HTTP-render level; no new page or frontend change |
| Customer | Not affected: no shared API, identity or runtime files changed |
| Driver | Not affected: no auth/dispatch/runtime contract changed |
| Van | Not affected: no B2B fulfillment/field operation contract changed |

The platform constraints **B2B → Van only** and **B2C → Driver only**, store isolation, entitlements and financial maker/checker are unchanged. Staging browser/manual accessibility and viewport inspection are deferred to the final V2 acceptance program under #2503; automated controls remain mandatory before closing this leaf.

## Verification and closure

Focused command: `cd backend && php artisan test tests/Feature/V2SidebarLayoutPresentationWhitelistTest.php`. Run repository CI-parity, Laravel suite, PHP formatting, static analysis, AR/EN, Security, V2 Isolation and final exact-head Required CI before merging. Evidence must include PR, exact final SHA, green required checks, and `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL`. No `main`, tag, release or production deployment.

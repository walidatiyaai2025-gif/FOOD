# V2 P367 / #2890 — Role Sidebar live denial preview

## Placement and authority
- **Reuse/extend**, not new route or screen. Administration > Page Tree & Permissions, existing `admin.sidebar-access.roles` (SidebarManagementController::roleAccess), `roles.manage` authorization, canonical `admin.security.roles.update` POST/PATCH, and existing AdminSidebarManager role matrix.
- Domain Security / RBAC, entity Role, actor authorized role administrator, low-frequency governance workflow, existing admin Page Tree settings surface, inline read-only preview inside current role editor. Native form checkboxes remain canonical role Permission Codes. No extra data inputs, menu items, API calls, security/route grants or mutated role-visibility records.
- Preview checks the selected role's existing metadata constraints (scope, channel allowlist, excluded role, global presentation disable, per-role visibility preference) and unsaved checkbox permissions, respecting `any`/`all` mode and Super Admin role-matrix exception. A disabled or personally hidden page is **not** described as an HTTP 403.
- **Security boundary:** This is not a server-side auth decision or impersonation. The preview now reads enterprise module lifecycle, exact-version certification and health via the same `ModuleRegistry` + `ModuleOperationalReadiness` services as canonical navigation and explicitly blocks unready module nodes. Actual per-user effective permission overrides, multi-role composition, tenant/company/store, module entitlement, route/action/SoD and user-specific authorization **remain unverified** and explicitly labeled as such. Readiness is a bounded, fail-closed platform-level projection (one check per distinct module), not an entitlement grant. The existing backend route and RBAC are the only authorities.

## AR/EN and accessibility
- Translation strings provided for Arabic RTL and English LTR; role status wording distinguishes missing scope/channel/permission, disabled page/group, preference-hiding and module not-yet-verified.
- Native checkbox keyboard behavior; read-only live preview recomputed on change, textual reason list built by `textContent` (no HTML injection) and one accessible polite summary per form. Reuses shared FOODEX admin shell, responsive Page Tree grid and theme tokens; no extra screen/route.
- Error/denied behavior follows existing `roles.manage` protected route and role mutation controller. No network request or write from preview.

## Acceptance and impact
- Laravel Feature: AR/EN render, RTL/LTR, checkbox-to-permission metadata, store-vs-global scope flag, **uncertified enterprise module gets a server-derived blocked indicator in both locales despite role permission**, readonly GET leaves role grants/audit/visibility unchanged, unauthorized GET/PATCH 403.
- Customer: **not affected** — no mobile API, authentication, screen, checkout or channel contract change.
- Driver: **not affected** — no Driver API, GPS, dispatch, mobile UI change.
- Van: **not affected** — no Van API, visit, B2B fulfillment or mobile UI change. B2B→Van only, B2C→Driver only remains.
- Requires exact-head Repository Policy, V2 Isolation, Backend/Pint/PHPStan, AR/EN and Required CI checks before integration; independent responsive (320–1280), keyboard/focus and real isolated V2 staging role/tenant/module acceptance is still required before closing Issue #2890. **No main, release, production or cPanel mutation.**

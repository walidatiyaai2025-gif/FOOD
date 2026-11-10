# V2 P384 — Existing Dashboard Sidebar Arabic naming

Source: FOODEX Unified RBAC/ERP/Sidebar document, native paragraph P371 (legacy matrix P384); [Issue #2904](https://github.com/walidatiyaai2025-gif/FOOD/issues/2904).

## Placement and scope

- **Reuse** the existing Dashboard `AdminNavigation::groupsFor`, `AdminSidebarManager`, and canonical Blade sidebar. No new screen, route, permission, role, entitlement, menu node, ERP activation, or data scope.
- Apply Arabic wording corrections to **existing** Laravel `admin.*` language keys, preserving English key/value parity and user-configured `label_ar`/`label_en` Sidebar overrides.
- Canonical destination is the existing role-filtered Sidebar (`SIDEBAR`); desktop and off-canvas mobile use the same server-projected labels. RTL/LTR changes are not needed.

| Existing translation key | Standardized Arabic |
| --- | --- |
| `admin.customer_360` | العملاء 360 |
| `admin.nav_groups.erp` | تخطيط موارد المؤسسة (ERP) |
| `admin.nav_groups.catalog` | المنتجات والكتالوج |
| `admin.nav_groups.advertising` | الإعلانات |
| `admin.nav_groups.analytics` | التقارير والتحليلات |
| `admin.catalog_management` | إدارة المنتجات والتصنيفات |
| `admin.retail_store_provisioning` | تهيئة متاجر التجزئة |

These are label changes to currently represented concepts, not authority for the 177 target Sidebar items or for opening unready ERP modules. Existing custom Sidebar names intentionally take precedence when configured; no destructive live database rewrite is performed.

## Authorization, tests and app-impact classification

- B2B/B2C, store and platform role access still derive from existing server-side routes/permissions and module readiness. No security rule was added or weakened.
- `V2ArabicSidebarNamingTest` locks the seven Arabic keys, checks English translation parity, verifies AR/EN navigation retains identical route/permission/parameters, tests an authorized catalog endpoint and direct-URL 403 for an unprivileged account.
- `AdminSidebarManagementTest` already covers configured bilingual overrides, nesting and RBAC role visibility. Existing `AdminNavigationAuthorizationAuditTest` covers wholesale/retail store visibility and direct ERP access denial.
- **Customer Flutter:** not affected; no Customer endpoint, permission, payload, locale table or app asset changed.
- **Driver Flutter:** not affected; no Driver endpoint, permission, payload, locale table or app asset changed.
- **Van Flutter:** not affected; no Van endpoint, permission, payload, locale table or app asset changed.

## Gate

Keep Issue #2904 open until latest exact-head Laravel/lint/localization/Required CI and integrated `Foodex_V2` acceptance are confirmed. This document is not a production or full 177-item Sidebar acceptance claim. No merge to `main`, release, tag or production deployment is authorized.

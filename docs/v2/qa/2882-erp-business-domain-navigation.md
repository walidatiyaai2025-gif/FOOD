# V2 #2882 — P358 business-domain distribution of ERP Sidebar modules

Source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx / P358; parent #2503. Target **Foodex_V2 only**.

## Placement and authority

**Reuse → Extend** the existing Dashboard authorized Sidebar, not a new page, group or permission model. Canonical owner: `App\Support\AdminNavigation::groupsFor`; filtering and saved presentation preferences: `App\Services\AdminSidebarManager::apply`. Normal navigation type: **SIDEBAR**. ERP Center `admin.erp.index` remains the canonical **DOMAIN_HUB** retaining the full P118 business workflow/cards sequence, with customer service/my-work shortcuts in the ERP group.

| Existing ERP link | Group | Canonical real route | Permission / enterprise module |
|---|---|---|---|
| erp_procurement | catalog | admin.erp.procurement.index | procurement.suppliers.view / procurement |
| erp_wms | catalog | admin.erp.wms.index | inventory.view / inventory_wms |
| erp_finance | finance | admin.erp.finance.index | finance.view / finance |
| erp_treasury | finance | admin.erp.treasury.index | finance.view / treasury |
| erp_bi | analytics | admin.erp.bi | reports.view / enterprise_bi |

No new route, screen, API, role, module enablement or data mutation. Both static source grouping and metadata default groups map to the same business domain, including for Platform Owner, while saved administrator placements remain presentation-only and cannot grant access. Existing role + exact route + channel/tenant/store + lifecycle/entitlement/readiness checks remain authoritative; ERP visibility never proves operational certification.

## Evidence and acceptance

- New `V2ErpBusinessDomainNavigationTest`: live AR RTL / EN LTR ERP HTTP and exact-once allowed group placement; retail-only role still denied direct ERP URL.
- Updated `V2WholesaleErpNavigationSequenceTest`: proves P118 Center card sequence unchanged and validates domain grouping, sort uniqueness within each group, exact-once links and registered destinations. Existing P361, P360/P106 and entitlement/role denial tests cover layout override precedence, failing modules and direct-route 403.
- Dashboard mobile web, tablet and desktop: existing Sidebar tree and native links unchanged except group location. Stage browser still must verify 320/360/390/412/430/768/1024/1280px, AR/EN, RTL/LTR, keyboard/focus/screen-reader without inventing evidence.
- Customer app **NOT AFFECTED**: no Flutter/API/auth/deeplink/cart changes.
- Driver app **NOT AFFECTED**: no driver API, dispatch or fulfillment changes. B2C remains Driver only.
- Van app **NOT AFFECTED**: no Van API, collection/route changes. B2B remains Van only.
- No migration/data edits; reverting grouping restores legacy presentation without altering permissions or user settings.

## Required delivery proof

One Issue, one branch, one PR base Foodex_V2; exact-head Repository Policy, V2 Isolation, localization AR/EN, PHP syntax/Pint/static/security/OpenAPI, Laravel features, MySQL/Redis install-upgrade-recovery, Required CI Gate children green before isolated merge. Keep Issue OPEN for any unverified real-browser accessibility/runtime acceptance. **No main, tags, release or production.**

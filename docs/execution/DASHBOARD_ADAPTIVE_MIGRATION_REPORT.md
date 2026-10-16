# Dashboard Adaptive Route Inventory & Migration Report

Issue: **#1941**  
Parent: **#1939**  
Baseline: `79dacf258cc9e7c71498cbdec4e9ae52699a10b0`

## Executive result

The current FOODEX Dashboard already has a substantial canonical product architecture. This inventory therefore **does not propose a screen-by-screen rewrite**. The default decision for current routes is **KEEP** and future/touched work follows **Reuse → Extend → Compose → New Screen**.

The inventory covers the business-facing Dashboard route families from `backend/routes/web.php`, the current Smart Ordering / Restaurant admin routes, route authority, sidebar/navigation contracts, responsive tests and the runtime evidence matrix. Supporting feed/AJAX/export/download/asset endpoints are not promoted into independent screens.

## Product architecture decisions

- **ERP** — `/admin/erp` is the canonical ERP entry. Procurement, WMS, Finance, Treasury, Customer Service, BI and Action Center reuse the already-delivered #1906–#1913 lineages. No second ERP navigation tree or duplicate workbench is required.
- **Administration** — `/admin/administration` remains the Admin Hub; organization/company/legal-entity/business-unit and module-health routes are exact-record/sub-surface routes.
- **Settings** — `/admin/settings` owns platform configuration. WhatsApp, mobile, release/update, translations and other settings remain owned sub-pages instead of top-level feature dumping.
- **Field Operations** — the existing Field Operations workbench owns fleet, vans, assignments, visits, territories, address quality, routing and field finance. Driver live tracking remains a monitoring surface in that domain.
- **Customer Service** — Customer 360 is the canonical record context; ERP Customer Service composes/deep-links into Customer 360, Orders, invoices, delivery and conversations rather than cloning their data.
- **B2B/B2C Orders** — the named B2B/B2C order routes already use the canonical Order Operations surface.
- **Commercial Sales Control** — the old B2C sales-control destination is already a compatibility redirect to the canonical B2B commercial route. Do not recreate a duplicate B2C screen.
- **Restaurant SaaS** — current `admin/smart-ordering/**` control, menu, QR, supply and guest-growth routes are the delivered #1445/#1560 baseline referenced by #1572. Future Restaurant SaaS work extends/composes these authorities; it must not create MenuV2, OrdersV2, CRMV2 or QRV2 solely because a new feature Issue exists.
- **Generic enterprise workspaces/reports** — preserve existing contextual routes. ERP Center is the ERP discovery layer; this does not justify deleting or cloning shared contextual workspaces.

## Inventory summary

The machine-readable ledger is `docs/execution/DASHBOARD_ADAPTIVE_ROUTE_INVENTORY.json`. It records for every route family:

- domain, primary entity, role and user goal;
- current/recommended pattern and placement;
- navigation owner;
- overlap/duplicate status;
- form/grid/direct-record/shared-component state;
- responsive, AR/EN and accessibility state;
- severity, classification and remediation owner.

The route families are deliberately grouped by one durable user/workflow context rather than pretending every GET/AJAX endpoint is a screen.

## Remediation truth

### P0 — Shared responsive evidence gate

**Classification:** SHARED-FIX  
**Owner:** #1943

Current `main` has shared responsive CSS tests and AR/EN runtime evidence at 1280 / 1024 / 768 / 390. The full mandatory runtime matrix and critical assertions are being added on the existing #1943 lineage. This inventory must **not** create a second responsive framework or Issue.

### P1 — Placement adoption in active ERP / Restaurant work

**Classification:** MOVE/COMPOSE  
**Owner:** #1944

#1944 is the correct coordinator for applying the new placement metadata and responsive DoD to still-active/touched ERP #1905/#1914 and Restaurant SaaS #1572 work. It must reuse each existing atomic child/branch/PR.

### No new unowned remediation Issue

This inventory found **no additional route-level P0/P1 gap that is both confirmed and unowned**. Creating speculative Issues from route names would violate #1939. #1942 may audit shared primitive families after this inventory, but it must create code only where a concrete repeated component defect is proven.

## Responsive / localization / accessibility status

Do not infer full runtime compliance merely because a route exists or a shared CSS test passes.

- shared Admin layout contracts cover desktop/tablet/mobile breakpoints;
- current runtime matrix on `main` covers AR/EN at 1280, 1024, 768 and 390;
- #1943 owns expansion/enforcement for 320, 360, 390, 412, 430, 768, 1024 and 1280+;
- each changed route still requires exact-head runtime evidence for applicable loaded/empty/error/menu/modal/tab/map/chart states;
- accessibility remains a route-level acceptance requirement; unchanged legacy screens are **not** grandfathered by static checks.

## ERP mapping

| Domain | Canonical surface | Existing owner |
|---|---|---|
| ERP landing/navigation | `/admin/erp` | #1906 |
| Procurement | `/admin/erp/procurement` | #1907 |
| WMS | `/admin/erp/wms` | #1908 |
| Finance | `/admin/erp/finance` | #1909 |
| Treasury | `/admin/erp/treasury` + owned master data | #1910 |
| Customer Service | `/admin/erp/customer-service` | #1911 |
| My Work / Action Center | `/admin/action-center` | #1912 |
| BI | `/admin/erp/bi` | #1913 |
| Final role-journey runtime proof | all above | #1914 |

No implementation child is duplicated by this inventory.

## Restaurant SaaS mapping

Current delivered admin authorities include:

- `/admin/smart-ordering/settings` — runtime/site/publish control;
- `/admin/smart-ordering/menu` — canonical menu admin;
- `/admin/smart-ordering/qr` — QR management;
- `/admin/smart-ordering/supply` and site supply — supply intelligence;
- site/customer routes — customer history, loyalty and service recovery;
- `/smart-ordering/{siteKey}` — published guest storefront.

These stay the baseline for #1572. New Restaurant capabilities must first decide whether they extend these contexts, compose into them, or genuinely require a new route.

## Next execution

1. Merge #1943 when its exact-head runtime/CI gates are green.
2. #1942 audits **shared primitive gaps only**, using this inventory; no parallel design system.
3. #1944 applies the placement/responsive DoD to active ERP/Restaurant lineages without replacing their branches/PRs.
4. #1939 closes only after the inventory, shared pattern coverage, adoption and runtime convergence requirements are genuinely satisfied.

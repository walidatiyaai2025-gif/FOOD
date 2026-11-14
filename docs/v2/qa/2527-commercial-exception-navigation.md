# V2 #2527 / P197 — Commercial Exception Approvals

Source: FOODEX Unified RBAC ERP Sidebar Discussion, 2026-11-09, Customer & Sales P197. Parent #2503. Integration: `Foodex_V2` ONLY.

## Canonical placement and use

Domain Sales; entity: Order Customer Service concession requests; actors: authorized Wholesale manager or Retail store approver; goal: review pending requests, approve/reject exact order. Reuse (not create) the existing operational `OrderOperationsController::index` pending review workbench and `reviewCustomerServiceDiscountRequest` (P230/#2560). Pattern is a high-frequency operational inbox and contextual exact-record action. Existing route: `admin.operations.orders.index` with `channel`, `store_id`, `discount_view=pending`, `action_required=0`. Navigation: `SIDEBAR / sales / commercial_exception_approvals`. Back/fallback: Orders workspace and Sales sidebar.

Permission inventory: `orders.view`, `orders.customer_service_discount`, `orders.approve`, ALL in a single active store/channel. Navigation intersects the three sets from `OperationalTenantScope` and chooses a deterministic eligible store; HTTP controller independently repeats this intersected authority and issues 403 on missing permission or forged channel and 404 on foreign store. Review POST retains existing maker/checker, audit, replay and price constraints. No new entitlement/feature flag: this is an existing ready Orders route; role inactivity hides it. A cosmetic Sidebar toggle may hide the link but does NOT grant or revoke backend authority.

Arabic: موافقات الاستثناء التجاري. English: Commercial Exception Approvals. Existing shared Dashboard shell/Sidebar support RTL/LTR, responsive 320–1280+ px web layouts and keyboard links. No new screen, CSS, form, API, database table or direct-URL-only functionality.

## Impact matrix and acceptance

| Surface | Impact |
| --- | --- |
| Laravel Backend | Navigation projection only; no controller/route/policy change. Existing P230 approve/reject service preserved |
| Dashboard | Scoped Sales link with AR/EN; existing responsive shell reused |
| Customer | Not affected; no pricing, checkout, discount or API payload changes |
| Driver | Not affected; no delivery, assignment, collections or lifecycle change |
| Van | Not affected; no B2B direct-sale, route, custody or offline contract change |

Automated `V2CommercialExceptionApprovalsSidebarTest` checks registry, AR/EN, real pending route, B2B/B2C tenant and permission intersection, explicit deny, roleless, expired role, disabled presentation group and HTTP invariance. Existing `V2ExceptionApprovalWorkbenchTest` verifies real approvals/denials, no unrelated order, replay/audit. Required exact-head CI must be successful: Repository Policy, V2 Isolation, PHP syntax/Pint/PHPStan/security, localization, full Laravel tests and real MySQL/Redis install/upgrade/recovery with terminal `required-ci-gate`. No main, tag, release or production.

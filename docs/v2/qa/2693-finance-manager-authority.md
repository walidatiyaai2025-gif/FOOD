# FOODEX V2 — T122R13 / #2693 — Finance Manager

**Target:** `Foodex_V2` only. Source `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, row `T122R13`; parent #2503. No `main`, production, release, tags or cPanel operation.

## Reuse → Extend → Compose → New Screen
**Reuse** the canonical built-in `FINANCE` role, Finance Workbench (`admin.erp.finance.index`), route/middleware `EnsureErpOperationalReadiness`, `OperationalTenantScope`, `TenantContextResolver`, `ModuleEntitlementResolver` and existing `AdminNavigation` projection. **Extend** only missing role-specific HTTP regression evidence. No duplicate job role, route, Dashboard screen, permission policy, staging environment or Finance ledger is introduced.

The source role is a `global` tenant Finance manager admitted to B2B Wholesale by `config('admin.channels.b2b.global_roles')` and **not** B2C; unlike an Accounting Manager checker it has `finance.view`, `finance.manage`, `finance.post`, `reports.view/export` but **not** `finance.approve`. No `security.approve`, `roles.manage`, `users.delete` or `procurement.purchase_orders.issue` is inherited. Finance is an optional enterprise module for this built-in role, never an implied enabled/certified/entitled production workspace.

## Authorization and real workflow
| Surface | Intended effect | Proof |
| --- | --- | --- |
| Existing Dashboard Finance / Accounts | Finance manager can read an authorized B2B legal entity only after module lifecycle, certified version and `finance.foundation.read` entitlement | Real authenticated HTTP `GET admin.erp.finance.index` (Accounts tab) AR/RTL and EN/LTR |
| Account creation | `finance.manage` role permission alone is insufficient: no `finance.foundation.manage` module capability → HTTP 403, no ledger/account write | Real `POST admin.erp.finance.accounts.store`, exact record absence |
| B2B channel selection | Exact global role and authorized active B2B store; no inferred B2C retail scope | `OperationalTenantScope`, navigation channel and permission assertions |
| Explicit per-user store DENY | A store-specific `DENY finance.view` removes exactly that B2B Finance context even when legal-entity entitlement is present; another authorized store is unaffected | Two real B2B GET requests before and after the override, forbidden store no longer selectable |
| Module unavailable / uncertified | Deny all direct Finance workbench access until operational readiness and real module entitlement | Rejected HTTP paths for disabled, unentitled and uncertified; positive after readiness |
| Finance SoD | `finance.post` is distinct from `finance.approve`; manager cannot become checker by title | Persisted exact role bundle + effective `finance.approve` negative assertion, existing Finance maker/checker integration tests remain authoritative |

**Responsibility:** role and Finance/GL legal-entity boundaries remain backed by existing Finance and Workflow/Approval services. This issue does **not** add a new approval grant, alter posting state machines, infer approval from a menu, or weaken established audit/idempotency guards. Existing source-led `V2FinanceTreasuryDutySeparationTest`, `V2ProcurementFinanceTreasuryRoleSeparationTest`, `V2FinanceSidebarPersonaContractTest` remain in place, not copied. New focused test: `backend/tests/Feature/V2FinanceManagerRoleAuthorityTest.php`.

## Mobile and runtime impact matrix
| Surface | Classification | Evidence/contract |
| --- | --- | --- |
| Backend Laravel | **Affected: automated regression only** | Finance role, real HTTP route/permission/entitlement/SoD integration; no production PHP or API mutation |
| Management Dashboard | **Affected: verified behavior only** | Existing Finance workbench reused with AR/EN and RTL/LTR, no new markup/tab/route |
| Customer Flutter | **Not affected** | No Customer API, identity, basket, pricing, credit, invoice, notification or Flutter code changed |
| Driver Flutter | **Not affected** | No B2C dispatch, wallet, route, delivery proof or token/API change; DRIVER ONLY remains |
| Van Flutter | **Not affected** | No B2B visit, dispatch, route, settlement, consignment or Van Flutter/API change; VAN ONLY remains |

## Evidence and release fence
- Focused invocation: `cd backend && php artisan test --filter=V2FinanceManagerRoleAuthorityTest`.
- Required final exact-head CI: Repository Policy, FOODEX V2 Isolation Gate, backend PHP/Pint/PHPStan/security/OpenAPI, AR/EN, Laravel suite, MySQL/Redis fresh install/upgrade/recovery and final `required-ci-gate`. **Never merge on build alone.**
- Verify one PR, `base.ref=Foodex_V2`, exact matching head SHA, no overlapping worker files, successful terminal checks and actual merge result before stating completion.
- Full manual Finance Browser/mobile-web accessibility, real production financial approval/UAT and any remaining source-line acceptance stay under #2503/#2750 until separately evidenced. This test-and-documentation PR does not claim those outcomes.

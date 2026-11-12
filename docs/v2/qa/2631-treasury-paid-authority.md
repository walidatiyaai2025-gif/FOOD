# V2 #2631 — P301 «المدفوع» / Treasury paid-action authority

- **Source**: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, Sidebar P301, parent #2503.
- **Integration**: `Foodex_V2` exclusively, no `main` or deployment.
- **Placement**: **Reuse / Extend** the existing Treasury Workbench, `admin.erp.treasury.index?tab=payments&store_id=…`. No standalone route, no duplicate sidebar destination and no raw ID input.
- **Domain / entity / workflow**: Finance & Treasury / `EnterpriseTreasuryPayment` / paginated payments list, bounded store-scoped draft creation, independent posting, draft cancellation. Navigation owner is the existing Finance/Treasury business domain; existing Workbench tab is the PAGE_TAB entry point.
- **Role / authority**: Dashboard finance-view actor plus valid retail store assignment, module enabled/ready, scoped `treasury.payments.read` entitlement for the payments tab. Create/cancel require `finance.manage` plus `treasury.payments.manage`. Post requires `finance.manage` plus `treasury.payments.approve`, and must not be offered to the same payment's `requested_by` actor. All HTTP mutations retain server-side service enforcement.
- **Source data / bounds**: `TreasuryPaymentService::paginatePayments` provides real tenant/company/store-filtered pagination (25 rows), status/date/type/search validation and exact record actions. Bank/cashbox options come from existing authoritative Treasury Master Data; no new data sources.
- **UI**: existing localized `enterprise_treasury` EN/AR keys, RTL/LTR shell, `foodex-card`, `foodex-table`, `erp-filter`, `erp-actions`, `foodex-primary`; payment actions are now projected by the same module capabilities enforced by `TreasuryPaymentService`. The maker cannot see a self-post action. State-changing buttons remain normal protected POST forms with CSRF.
- **Permission model**: UI is only a projection; independent controller/service authorization and `EnterpriseScopeAuthorizer` cannot be bypassed by direct POSTs or copied URLs. Cross-store read yields 403 and mutation of a foreign payment ID is concealed by scoped lookup (404). No added service privilege, implicit approval or blanket Super Admin bypass.
- **Audit/SoD**: existing service persists `enterprise.treasury.payment_created`, `enterprise.treasury.payment_posted`, and `enterprise.treasury.payment_cancelled`; separate maker and checker are mandatory for posting. Posted payments are immutable to draft cancellation (reversal is a separate controlled workflow). Idempotency key prevents duplicate drafts.
- **Fields**: target is Lookup from scoped cashboxes/bank accounts; status is Enum; amount/date/reference/beneficiary/description are legitimate user-entered values; `_operation_key` is an internal protected form token, not a typed customer field.
- **Responsive/accessibility**: preserved existing page tabs, visible status labels, form labels, horizontal bounded table and existing responsive CSS; no new screen, sidebar, visual token or raw status values introduced.

## Executable regression

`php artisan test --filter=V2TreasuryPaidAuthorityTest` from `backend/`:
1. View-only actor sees EN/LTR and AR/RTL payments tab, but no mutation form and direct create gets HTTP 403.
2. Full-scope maker creates a real draft; retry with same idempotency key yields exactly one payment with audit provenance.
3. The maker cannot see or execute self-post; checker without `treasury.payments.approve` cannot see or execute post, even with `finance.manage`; after explicit entitlement, checker posts and audit shows immutable posted record; posted cancellation is rejected.
4. Retail store B cannot see store A tab or post/cancel store A payment by direct URL (403/404 respectively).

Additionally run mandatory repository Laravel/Pint/PHPStan/security/localization and exact-head GitHub Policy, V2 Isolation and FOODEX Required CI (including MySQL/Redis fresh-install/upgrade/recovery). **A green build is not a substitute for evidence of the full source acceptance.**

## Customer / Driver / Van impact

- **Customer**: not affected. Dashboard Blade action projection and Dashboard-only Feature regression; no change to Customer REST API, authentication, order, pricing, notification or Flutter source.
- **Driver**: not affected. No B2C dispatch, driver wallet, proof or delivery API change; Driver-only runtime and API contracts untouched.
- **Van**: not affected. No B2B Van route, visit, assignment, fulfillment or finance integration event change; Van-only runtime and API contracts untouched.

No mobile Issue is needed for this *specific diff* because public app endpoints, schemas, response contracts and mobile package files are unchanged. The existing Treasury payment service and integration event publisher remain authoritative.

## Final acceptance record

- Automated tests/CI: **PENDING** until reported for the exact PR head.
- Visual/manual independent device acceptance: `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` per #2503 owner decision, not claimed as done.
- Existing business navigation discoverability and full P301 source requirements must be independently checked before closing #2631.

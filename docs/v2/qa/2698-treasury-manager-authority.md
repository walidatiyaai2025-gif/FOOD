# V2 #2698 — TREASURY_MANAGER (T122R18) scoped authority

## Source and ownership
- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, native table `T122R18`; parent #2503.
- Isolated target: `Foodex_V2` only. No `main`, production, release, tag, database migration or background deployment.
- Persona: built-in `TREASURY_MANAGER` (global Wholesale/Tenant management role), **not** `TREASURY_OFFICER`, `ACCOUNTING_MANAGER`, a Retail employee or Platform Owner.
- One issue branch `test/2698-v2-treasury-manager-authority`, one PR. Existing Treasury services and shared navigation retained.

## Reuse → Extend → Compose → New Screen
**Reuse** the existing Treasury workbench `admin.erp.treasury.index` and its Finance group entry `erp_treasury`, including receipts, payments, reconciliations and reports tabs. No route, secondary Sidebar policy, role-override engine, new UI screen or data model is added.

The canonical `job_roles.roles.TREASURY_MANAGER` already defines global tenant role, Treasury required module, `finance.view`, `finance.manage`, `finance.approve`, `reports.view`, `reports.export`. It intentionally lacks `finance.post`, security approval or procurement approval. The manager could not previously select the B2B operational tenant because `admin.channels.b2b.global_roles` omitted its code. Add only `TREASURY_MANAGER` to that Wholesale allowlist; do not add it to Retail. This admits the persona to an **existing** secured workspace, not an authorization grant by itself.

## Security and authoritative transitions
- **Entry:** Finance → Treasury workbench; `admin.erp.treasury.index`, plus existing receipt action route `admin.erp.treasury.receipts.store`. `AdminNavigation` now projects a Treasury link only when at least one permitted active B2B store has effective `finance.view`, the live Wholesale context check, and `treasury.receipts.read` module entitlement; its link explicitly carries `store_id` and `tab=receipts` so the controller cannot default to an earlier denied store.
- **Authority:** active built-in role, live role permissions, Wholesale channel/active B2B store selection, Treasury module lifecycle, certified operational readiness, then Store-scoped Treasury entitlement and specific capability on every action. Configuration channel membership alone never substitutes for `User::hasPermission` or Treasury service authorization.
- **Negative cases:** module disabled, unentitled read-only action, forged Retail `store_id`, no `finance.post`, no security or procurement approval. A user-level B2B store-specific `finance.view` DENY removes the denied store from the Sidebar candidate list even when its entitlement is present; the other authorized Wholesale store stays reachable. If the sole store is denied, Treasury must not be advertised. No silent cross-channel escalation.
- **Maker/checker:** `TreasuryReceiptService`/`TreasuryPaymentService` remain authoritative for independent posting, idempotency, audit and entitlement checks. Existing `V2TreasuryReceiptActionAuthorityTest`, `V2TreasuryPaidAuthorityTest`, `V2FinanceTreasuryDutySeparationTest` exercise those lifecycles. This issue does not weaken them.
- **AR/EN & accessibility:** existing canonical bilingual Treasury view and RTL/LTR layout reused; regression exercises the real authenticated page in both locales. No new labels or interactive UI elements.

## App impact (explicit)
| Surface | Classification | Contract |
| --- | --- | --- |
| Backend | Affected | One B2B operational-role membership fix; existing route/permission/tenant/module/entitlement/SoD rules retained and tested |
| Management Dashboard | Affected | Same authorized existing Finance → Treasury entry point becomes reachable by eligible Wholesale Treasury Manager |
| Customer Flutter | Not affected | No customer API, profile/price/order schema, client navigation or Flutter diff |
| Driver Flutter | Not affected | No B2C assignment/dispatch/collection API, schema or Flutter diff |
| Van Flutter | Not affected | No B2B assignment/visit/route/collection API, schema or Flutter diff |

B2B fulfillment remains VAN ONLY; B2C fulfillment remains DRIVER ONLY.

## Reproducible evidence and completion gates
- Test: `backend/tests/Feature/V2TreasuryManagerRoleAuthorityTest.php` checks persisted canonical role permissions, denied financial/security/procurement privileges, disabled-module fail-closed, bilingual authorized B2B HTTP/Sidebar, foreign Retail HTTP denial, per-store explicit DENY and first-store link selection, and a real negative receipt mutation with a read-only Treasury capability.
- Run `cd backend && php artisan test --filter=V2TreasuryManagerRoleAuthorityTest`; confirm existing Treasury regressions and full required Laravel/PHP/Pint/static-security, AR/EN, V2 Isolation Gate and Repository Policy on exact PR HEAD.
- Do not merge unless all required checks are terminal green on the exact final head and base remains `Foodex_V2`. Do not close the issue based only on unit tests; preserve final V2 cross-persona visual/acceptance evidence under #2503 where not yet verified.
- This is an implementation and regression contract, **not** evidence that production, full user UAT, all ERP workflows or cPanel deployment were tested.

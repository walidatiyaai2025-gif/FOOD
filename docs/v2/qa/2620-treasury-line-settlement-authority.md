# V2 P290 — Treasury line settlement / bank movement matching

- **Issue:** #2620 (Sidebar 290 — تسوية البنود), parent #2503
- **Source:** FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, P290
- **Integration:** Foodex_V2 ONLY; no production or main promotion.
- **Placement:** **Reuse** the existing Finance & Treasury workbench (`admin.erp.treasury.index?tab=reconciliation`) rather than creating another Sidebar item, screen, or ledger.
- **Entry point:** Authorized Treasury workspace > Bank reconciliation tab. The matching control uses user-facing posted receipt/payment identifiers in authoritative scoped lookups instead of manual database IDs. The server owns the settlement decision.
- **Actor/scope:** Finance role with actual store/channel authority and enabled Treasury module. Require `treasury.reconciliation.read` for access, `treasury.reconciliation.manage` for matching, `treasury.reconciliation.approve` for closing, in addition to canonical server-side finance permissions. Organization/company/store, bank account, currency and period constrain eligible movements.
- **Workflow:** Create idempotent statement draft → post actual bank movement under checker authority → match receipt/payment once → validate matched amounts against statement totals → independent checker closes → immutable audit and integration event. Never match a foreign-store, foreign-bank, out-of-period, unposted or incompatible-currency movement; maker cannot self-close.
- **Security regression:** new P290 feature test verifies posted bank receipt and actual UI selector in AR/EN RTL/LTR, idempotent matching, maker/checker separation, closing audit, closed-state rejection, foreign-store denial. Existing P297 reconciliation contract tests cover disabled module, read-only entitlement and permission DENY.
- **AR/EN / accessibility:** existing Treasury tab has localized labels, semantic forms and CSRF, and no new UI primitive or navigation destination. Manual visual/mobile-browser acceptance remains separate.
- **Mobile impact:** Customer — **not affected**; Driver — **not affected**; Van — **not affected**. This is Dashboard-only, with no REST API, shared DTO, dispatch, mobile route or Flutter file changed. B2B VAN-only/B2C DRIVER-only invariants remain unchanged.
- **Changed files:** only `backend/tests/Feature/V2TreasuryLineSettlementAuthorityTest.php` and this doc. Shared Finance/navigation/controller/other worker-owned files untouched.
- **Verification:** `php artisan test --filter=V2TreasuryLineSettlementAuthorityTest` plus exact-head Repository Policy, FOODEX V2 Isolation Gate, Required CI Gate including Laravel/Pint/static analysis/localization/MySQL. Link verified run IDs and head on Issue/PR before merge.
- **Acceptance status:** Runtime/manual UAT and final parent #2503 release acceptance are separate; no production sign-off claimed.

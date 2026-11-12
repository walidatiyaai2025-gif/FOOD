# FOODEX V2 #3269 — P280 Finance entitlement and navigation parity

**Scope:** Original V2 #2610 / PR #3265 and Finance group #2609 / PR #3268 are already merged; this is a distinct follow-up for store-effective Finance authorization. One issue, one branch `test/3269-v2-finance-entitlement-parity`, one PR to `Foodex_V2` ONLY. No main, tags, deployment, releases, production or real account changes.

## Source and root-cause reconciliation

The existing `EnsureErpOperationalReadiness` middleware protects the entire `admin.erp.finance.*` named-route family with the same exact-version `ModuleOperationalReadiness` authority as `AdminNavigation::erpModuleReady`. Its fail-closed behavior is **reused**, not implemented twice. Controllers independently retain RBAC, tenant scopes, Finance maker/checker, legal-entity permissions and audit.

The real discrepancy is entitlement **scope**: Finance Workbench HTTP reads previously checked LEGAL_ENTITY capabilities while its Sidebar projected PLATFORM/STORE entitlements, including no enumeration of B2B Wholesale stores. The controller's context picker checked only `finance.view`. A valid LEGAL_ENTITY grant with an explicit narrower STORE deny/capability restriction could therefore make finance reachable directly despite a missing Sidebar link; a STORE-only grant must not create a Finance ledger permission.

## Canonical placement and security decision

Reuse existing Finance & Treasury Sidebar group (`erp_finance`), real `admin.erp.finance.index` route, tabs and all existing 33 Finance endpoints. No new UI, route, permission or independently managed visibility flag.

For each operational store, eligibility requires: active actor; effective Finance role/action permission at that store; **LEGAL_ENTITY capability**; and the matching **STORE-effective capability**. Entitlement lineage already handles inherited company/organization/platform grants but lets narrower STORE denies override them. Module lifecycle, certification and health are enforced by existing server middleware before the controller; direct URL cannot skip it. This same decision is now used to choose Finance contexts, Finance Sidebar reachability and Finance mutations. Existing domain services still enforce legal-entity scope and maker/checker/approval limits.

- Inactive, roleless and direct URL / forged store actors remain denied by existing auth and scope guards.
- A store-level module disable excludes both navigation and GET/POST. A store capability restriction to read-only permits the authorized reading journey but denies Finance account changes, with no database change.
- The existing AR/AP Receivables group uses the same per-STORE + LEGAL_ENTITY read capabilities as each direct Finance tab (not legal-entity-only); a `finance.foundation.read`-only STORE cannot advertise or read `receivables`/`payables`. Both module-level and AR/AP Sidebar store enumeration use the exact bounded HTTP context chooser (channel/store/order/100-row limit).
- A store-only entitlement does not bypass absent/denied LEGAL_ENTITY Finance capability.
- B2B FINANCE operator can discover legal-entity-entitled Wholesale Finance; Retail B2C requires both its authorized store assignment **and existing global ERP admission**, rather than gaining access from a Sidebar-only permission.
- A platform Super Admin with platform-level Finance entitlement and ready module may open the existing **empty/no-context setup view** before any eligible store is provisioned; no ledger tab or mutations become available from this path. An actual store-specific deny still denies that store and its records.
- Cross-legal-entity Finance resource IDs return their canonical **404** before any later store-effective write capability check; no cross-scope journal or opening balance is disclosed.
- No finance ledger capability is granted by presentation. No silent permission expansion.

## Source files and acceptance

- `backend/app/Enterprise/Finance/FinanceWorkbenchAccess.php`: canonical eligibility and combined legal-entity/store capability resolution.
- `backend/app/Support/AdminNavigation.php`: Finance-only projection of eligible Store/Wholesale operational contexts; unrelated module visibility preserved.
- `backend/app/Http/Controllers/Admin/FinanceWorkbenchController.php`: authorize read context options and per-action mutations against the same Finance eligibility.
- `backend/tests/Feature/V2FinanceEntitlementParityTest.php`: real AR/EN RTL/LTR Finance GET and Sidebar; authenticated Wholesale; legal-entity inheritance; explicit store deny; store read-only; store-only no entity entitlement; uncertified module direct GET/POST denial; no unauthorized account persistence.

**Per-app impact assessment:** Customer **not affected** (no Customer API, products, price, identity or Flutter change). Driver **not affected** (no B2C dispatch/driver API, GPS, wallet or app change). Van **not affected** (no B2B routing/visit/van-client API change). Existing B2B=Van only and B2C=Driver only unchanged. Dashboard only, using the existing responsive AR/EN UI and ERP placement.

**Acceptance gates:** Exact latest-head GitHub `Repository Policy`, `FOODEX V2 Isolation Gate` and terminal `FOODEX Required CI Gate` including Laravel tests, PHP syntax/Pint/PHPStan/security, localization AR/EN, MySQL/Redis fresh-install/upgrade/recovery must all complete SUCCESS before a SHA-guarded Foodex_V2-only merge. No CI/UAT success is asserted before the runs finish. Manual responsive/keyboard/device/staging UAT remains `DEFERRED_TO_V2_FINAL` by #2503; genuine automated source failures cannot be deferred.

**Review note:** This change introduces a finer Finance guard; non-Finance module entitlement logic and any cross-app communication paths must remain unchanged. Any new exact-head CI failure is fixed in this same branch/PR rather than weakening a required check.

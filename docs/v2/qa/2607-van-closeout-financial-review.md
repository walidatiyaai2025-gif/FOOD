# V2 P277 — Van closeout financial review (bounded delivery)

Issue #2607, parent #2503. Integration target: `Foodex_V2` only.

## Placement and authority
- Domain: Fleet/Van Operations with Finance settlement review.
- Entity: canonical `collection_accounts` for `actor_type=van` scoped by active Wholesale store and currency; NOT a trip session.
- Role: authenticated Super Admin or `finance.view`, additionally constrained by authorized B2B store IDs. Unauthorized = HTTP 403; unauthorized Retail/other store records never appear.
- Goal/workflow: inspect financial custody and unreconciled remittances before considering operational closeout. Read-only, high-frequency finance audit, tabular bounded pagination.
- Existing parent: Administration Hub > Van Finance Support, canonical `admin.van-finance-support.index`, `VanFinanceSupportController`.
- Placement: **Extend** existing canonical page with `ops_tab=closeout` PAGE_TAB. No new Sidebar group, route, controller or entitlement; no duplicate ledger.
- Navigation: Administration Hub card > Van Finance Support > Closeout Review. Desktop/tablet/mobile use existing route and responsive page; RTL and LTR use paired `van_finance_support` translations; no new mutating UI states.
- Data: `custody_ledger_entries` net balance, pending `remittances`, approved-awaiting-reconciliation count. Computed status is **ledger review**, never **trip closed**. Stale/empty ledger = no financial blockers observed, not proof that a trip exists or may be closed.
- States: empty, financial-review-required, financial-ledger-clear. The tab performs no POST or side effect.
- Mobile impact: Customer **not affected** (no API/price/order change); Driver **not affected** (excluded from the tab, B2C remains DRIVER ONLY); Van **not affected on app contract** (no Van endpoint or screens changed, existing B2B VAN ONLY). A future authoritative trip-close API requires its own cross-app contract verification.

## Source gaps blocking Issue closure
P277 asks for **route/trip closeout and settlement**, which cannot be proven using the current collection account ledger. Existing `van_visits` lifecycle closes individual visits, and legacy route metadata is not a durable, unique, finance-attributable trip session. Van custody is per collection account, not per trip. Neither a closed visit nor zero account balance authorizes declaring a trip financially settled. Before full P277 acceptance, establish source-backed unique trip identity, ownership, assignment/time boundaries, stock load/returns, visit/order inclusion, receipt attribution, maker/checker settlement, immutable audit and idempotent close/reopen rules. Do not fabricate `trip_id` or post closeout journal entries.

## Verification plan
- `php artisan test --filter=VanFinanceSupportTest`
- `vendor/bin/pint --test app/Services/FieldOperationsFinanceService.php app/Http/Controllers/Admin/VanFinanceSupportController.php tests/Feature/VanFinanceSupportTest.php`
- Full Laravel, localization, security and exact-head Required CI, Repository Policy and FOODEX V2 Isolation Gate.
- Pending interactive runtime checks: AR/EN, desktop/tablet/mobile 320–1280px and screen reader/keyboard. No release, main or production claim.

**Acceptance status:** bounded financial read-side implemented; full operational trip closeout **NOT YET COMPLETE**. Keep #2607 open.

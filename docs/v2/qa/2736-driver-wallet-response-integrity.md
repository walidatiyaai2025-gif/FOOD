# V2 #2736 — Driver wallet response integrity and finance scope (MOB-D03)

Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P033/T115R05. Parent #2503. Integration target **Foodex_V2 only**; no owner-approved `main` promotion or production deployment.

## Original behavior and defect

`backend/app/Http/Middleware/EnsureDriverRuntimeAccess.php` checks `app:driver` token ability, a currently active `b2c` Driver profile, current `B2C_DRIVER` role, and `deliveries.b2c.execute`. Backend `DriverCollectionController::wallet` queries only `CollectionAccount` with `actor_type=driver`, authenticated driver ID and exact Driver store ID; `remit` independently checks the same identity/store, active status, amount and idempotency key, while `collect` checks the driver's active delivery, invoice and authoritative amount due. Those checks remain authoritative.

Before this fix, the Driver HTTP `wallet()` client used `whereType<Map>()` to silently discard malformed collection accounts, and the existing `DriverWalletAccount.fromJson` similarly discarded malformed child orders, transactions and remittances. Missing/non-numeric custody and remittance balances defaulted to **zero**. Thus a malformed yet HTTP-200 server response could be shown as a partial or empty *successful* reconciliation, without an error or the wallet page's last-confirmed-data warning.

The fix stays in the **existing HTTP Driver wallet repository**, not a new finance authorization layer. For each account it now validates positive integer account ID, nonempty currency/status and numeric `custody_balance` and `available_to_remit`, plus typed optional `unsettled_collections` and `total_collected`. If `orders`, `transactions` or `remittances` keys are present they must be arrays containing only objects. Any invalid row rejects the **entire** response via existing `DriverApiException` before the domain parser can silently drop data. Valid empty wallets, multiple currencies, account statuses and server-confirmed negative ledger adjustments are not fabricated/blocked by new business policy. The data remains immutable after a successful parse.

The existing `DriverWalletPage._load` is the sole owner of wallet UI state and already preserves last-confirmed data, marks it stale on silent refresh failures, or displays an explicit error with retry on initial failures. The change makes malformed finance responses follow that existing failure path instead of showing a partial result. No new screen, route or Sidebar entry.

## Acceptance scope and evidence

- New hermetic test: `apps/driver_app/test/driver_wallet_response_integrity_test.dart`: exact B2C wallet GET with bearer token; confirms balances, collected orders, receipts and remittances; accepts authorized empty wallet; rejects non-object/missing/bad account and money fields; rejects mixed valid+malformed accounts atomically; rejects invalid child ledger arrays/entries; preserves HTTP 401 vs 403 distinction.
- Existing Flutter wallet-page tests: `apps/driver_app/test/driver_wallet_page_test.dart` cover AR/EN wallet rendering, stale last-confirmed balances after refresh failures, no duplicate remittance submission and modal lifecycle.
- Existing Laravel finance tests: `backend/tests/Feature/CollectionCustodyServiceTest.php`, `CollectionCustodyRemittanceLifecycleTest.php` and Driver authorization suite already exercise server custody/idempotency rules. The backend code remains unchanged and is NOT claimed rerun if changed-area CI skips it.
- Exact final-head required GitHub checks: Repository Policy, FOODEX V2 Isolation, Driver Flutter strict formatting/fatal-info analysis/hermetic tests, AR/EN localization, Driver Android release-validation build and terminal required-ci-gate; any failing check must be repaired **on this same branch and PR** before merge.

## Role, route, placement and cross-app impacts

Actor: authenticated B2C Driver. Business domain: collection custody/Driver wallet; entity: current scoped collection accounts and ledger entries. Route owner: existing Driver wallet tab in authenticated Driver shell. Placement decision **Reuse** current wallet page + repository (no additional UI). Entry point: existing authenticated Driver wallet navigation. Workflow: GET wallet → read authoritative balances/receipts → submit server-validated remittance; failure → existing explicit error/retry or last-confirmed stale status. Interaction type: existing page tab/workflow, back path: Driver shell. Mobile AR/EN/RTL-LTR/accessibility unchanged because no rendering edits.

| Surface | Impact | Contract |
|---|---|---|
| Laravel Backend/API | No runtime change | Existing current B2C role + Driver/store checks, ledger limits, idempotency, maker/checker and API payload remain authoritative |
| Management Dashboard | No runtime change | Existing custody and reconciliation approval/finance scopes unchanged; no new permissions |
| Customer Flutter | No runtime change | Customer account balances and collections independent of Driver's wallet parser |
| **Driver Flutter** | **Changed** | Existing `HttpDriverAssignmentRepository.wallet()` fails closed on malformed finance response; existing UI's stale/error contract retained |
| Van Flutter | No runtime change | B2B-only Van custody/remittance endpoints and device auth independent, no Driver B2B fallback |

## Explicit remaining broad issue acceptance

This small but material integrity fix **does not complete all of #2736** (P033/T115R05). Before closing the broad Issue, a subsequent authorized worker must demonstrate real API + UI B2C role/store/assignment lifecycle, permitted/denied collection, partial collection, idempotent remittance, backoffice maker/checker approval and reconciliation, cross-store and cross-app negative tests, notification and audit records, plus device/staging UX acceptance when owner final gate #2503 authorizes it. Do not claim that client-side parsing alone proves full financial SoD or settles cash. Leave #2736 OPEN in `INTEGRATED_PENDING_ACCEPTANCE` and release its worker lease after verified merge.

No `main` edits, releases, tags, production/staging deployment, APK publication or live account changes.

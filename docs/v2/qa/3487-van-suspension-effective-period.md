# V2 #3487 — Van suspension: effective-period and audit provenance

**Source:** #2738 MOB-V02; #2606 P276 Van route/assignment ownership; parent #2503 V2 isolated delivery. **Target: `Foodex_V2` only**, no main, release or production.

## Defect / authority

`VanRegistryService::suspend()` selects assignments by their row `status=active`, including rows whose effective dates are entirely past or entirely future. It previously overwrote `effective_until` for **all** those rows with the current time. Thus suspending on November 14 a planned December assignment could persist a nonsensical `effective_until < effective_from`; historically expired assignments also acquired an inaccurate later end date. This is an independently verified source-level temporal-data integrity flaw, not a claim that real users or live data have been affected.

## Canonical fix

- One transaction-scoped suspension timestamp `suspendedAt` is reused for each affected assignment.
- **Currently effective** row: `effective_from < suspendedAt` AND `effective_until IS NULL OR effective_until > suspendedAt`: update `status=ended` and `effective_until=suspendedAt`.
- **Historical row (already expired)**: `status=ended`; preserve its original effective date range, including the actual historical end.
- **Future/planned row**: `status=ended`; preserve original start/end, including open-ended plans, without recording an end earlier than start.
- Preserve the existing `loaded_work_count > 0` recovery/transfer requirement; failures make no suspension mutations. Preserve `transferred_to_van_id` and `transfer_reason` behavior and atomic `Van` status switch.
- All status-ended rows remain excluded from `effectiveAssignments()`; no new permission, endpoint, schedule, warehouse, ledger or dispatch authority is introduced.

## Automated source acceptance

`V2VanSuspensionEffectivePeriodTest` (Laravel, `RefreshDatabase`) covers:

1. Historical, currently effective, future finite and future open-ended Van assignments together; asserts all become ended, only the current interval is truncated and none becomes inverted.
2. Future loaded-work assignment: without a transfer target, validation failure leaves Van and assignment untouched; with explicit transfer target, status ends, dates remain intact, transfer target/reason persist.
3. Current open-ended assignment is ended at suspension, with a positive-duration historical interval and no remaining effective assignments.

Use days of separation between test fixtures and wall-clock `now()` to avoid an exact-time-boundary or scheduler granularity race. Existing `VanRegistryServiceTest` remains authoritative for general suspension behavior.

## Backend / Dashboard / Customer / Driver / Van impact

- **Backend:** one canonical Van registry domain service changes date-preservation on suspension. Request/response shape, record ownership, exception field keys and access controls are unchanged.
- **Dashboard:** existing Fleet/Van suspension operation retains its entry point, permission checks, confirmations and AR/EN feedback. No new button, route, Sidebar node or RTL/layout change.
- **Customer:** no Customer API, Flutter, checkout, identity, B2B/B2C order lifecycle or notification impact.
- **Driver:** no Driver API/Flutter, B2C dispatch, wallet, delivery proof or GPS tracking impact. B2C=DRIVER ONLY.
- **Van:** a correctly ended (previously planned) assignment is not represented as a malformed inverted period downstream. Van runtime/Flutter transport contract unchanged; B2B=VAN ONLY. Related complete mobile/runtime acceptance remains open under #2738.
- **Security:** no extra grant; existing role/scope, transfer-target requirement, store tenant isolation, finance and audit constraints preserved.

**Limitations:** This correction does not retroactively repair corrupt data on a live system, certify concurrency/empty-set locking, or complete broader P276/MOB-V02 acceptance. Parent issues #2606/#2738 and master #2503 remain open for full source and staging/manual acceptance. No live data was inspected or modified.

**Merge gate:** Exact *final PR HEAD* Repository Policy + FOODEX V2 Isolation + FOODEX Required CI, including backend Laravel/Pint/PHPStan/security, AR/EN and real MySQL/Redis where applicable, must all be terminal-green. Only then SHA-guarded PR merge into `Foodex_V2`. Capture tested head, merge SHA and CI run links in #3487 closure; code-leaf completion does not imply production installation, signed ZIP release or manual/live UAT.

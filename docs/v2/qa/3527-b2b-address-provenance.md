# V2 #3527 — Platform/B2B address provenance fail-closed guard

**Target:** `Foodex_V2` only. **Parents:** #2731 Customer, #2782 cross-tenant security, #2503 final V2 acceptance. No main merge, release, tag or deployment.

## Source finding

`CustomerAddressService::queryFor` previously combined Platform identity and independently B2B domain identity with OR semantics. An imported address containing two *populated but contradictory* identity foreign keys could be selected by either matching branch. For actual customer address endpoints, the same query also authorizes GET list/detail, PATCH, setting default and DELETE. This was a code-level provenance ambiguity, **not evidence of a live breach**.

## Bounded change

Retain the existing Platform/legacy/B2B-domain OR to recognize legitimate old records, but for a **Platform customer's B2B channel** additionally require:

- `platform_customer_id IS NULL OR platform_customer_id = authenticated Platform Customer ID`
- `b2b_customer_id IS NULL OR b2b_customer_id = request-authorized B2B Customer ID`

Both independent AND predicates are applied to the existing owner query before reading or mutating the address. Existing nullable old refs and old domain-owned B2B rows with an incorrect/defaulted `commerce_channel` remain eligible; a matching Platform FK no longer overrides a mismatching populated B2B FK and vice versa. Other B2C/non-Platform query behavior, response shape and routes remain unchanged.

## Automated source acceptance

`backend/tests/Feature/V2CustomerB2bAddressProvenanceTest.php` uses Laravel `RefreshDatabase`, `CoreReferenceSeeder` and authenticated Sanctum profile/address HTTP routes, with:

- Valid Platform/B2B row, domain-only legacy B2B row, and legacy-only Platform row retained and editable.
- Foreign populated Platform FK beside an otherwise owned B2B FK: hidden from list and 404 for detail/edit/default/delete by both actors.
- Foreign populated B2B FK beside an otherwise owned Platform FK: same 404 and non-mutation safeguards by both actors.
- Arabic and English authenticated denial responses without new locale keys.

Verification status: **source committed; exact-final-head CI pending**. Test success, integration SHA, and closure may be recorded only after terminal required gates pass on this exact head.

## Surface and security classification

- Backend / Customer API: owned-address filtering on existing authenticated endpoints, no schema/route/payload change; unauthorized linked records become 404.
- Dashboard: unchanged (no UI/navigation/module/permission updates).
- Customer Flutter: existing profile/checkout API shape unchanged; safer unauthorized results. Parent #2731 tracks customer cross-app authorization.
- Driver Flutter: unchanged; B2C delivery-only enforcement untouched.
- Van Flutter: unchanged; B2B fulfillment-only enforcement untouched.
- AR/EN and RTL/LTR: no new visible strings, screens or navigation; negative HTTP tests exercise both selected locales.
- Finance / warehouse / credentials / uploaded files: no modifications. No live data mutation or source migration.

**Final V2 staging/human UAT:** deferred to master #2503; this is not permission to waive source implementation or Required CI.

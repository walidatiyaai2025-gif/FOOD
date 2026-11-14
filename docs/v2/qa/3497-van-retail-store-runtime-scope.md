# V2 #3497 — Retail/inactive seller context excluded from legacy Van runtime

Parent #2738 Van MOB-V02, #2604 Van inventory, master #2503. Base `Foodex_V2` only; no release, main merge, production, cPanel, APK tags or deployment.

## Source audit and risk

`VanRuntimeContextResolver::resolve()` previously constructed `store_id` using `COALESCE(van_assignments.warehouse.store_id, vans.home_warehouse.store_id, drivers.store_id)` without checking the resolved store type or active state. An *existing legacy* assignment referencing a B2C Retail warehouse could therefore enter the selected B2B Van context. A home B2B store fallback must not hide an explicit Retail assignment warehouse. This is a code-path risk, not proof of a live tenant leak or a permission bypass outside the resolver.

## Atomic read-side guard

- Keep the canonical COALESCE priority unchanged; derive it once and reuse in both SQL seller-store join/filter and returned `store_id` projection.
- Filter **in SQL before selection**: a non-null effective store must exist, be active and have canonical store type `B2B`. A truly missing seller store ID remains nullable for supported representative-only operations.
- An active legacy Retail/inactive effective store never enters `selected` or `available`, including explicit preferred Van selection. Keep existing machine-readable `selection_reason` values, driver/representative authorization, temporal assignment and active-Van guards.
- Read-only guard: preserve historical rows, no migration, backfill or dangerous deletion. Write-side prevention for B2C warehouse inputs and legacy reconciliation are distinct follow-ups under #2738/#2604 and must not be claimed as solved.

## Tests, API and roles

New `backend/tests/Feature/V2VanRetailStoreRuntimeScopeTest.php` uses RefreshDatabase, canonical CoreReferenceSeeder and actual Van runtime resolver. It creates synthetic Wholesale B2B and Retail B2C stores/warehouses, legacy active assignments with Retail seller and B2B home store, then proves Retail filtered and preferred Van denied; also tests inactive B2B seller denial, valid B2B driver store fallback and null-store representative-only compatibility. This preserves B2B Van-only execution and existing #2911 protection against Retail Driver role elevation. Full exact-head GitHub CI required: PHP syntax/Pint/PHPStan/security/OpenAPI, Laravel tests, localization quality, MySQL/Redis install/upgrade/recovery, Policy, Isolation, Required Gate.

## Customer/Driver/Van impact

- Customer: no public Customer API, checkout, Flutter or store navigation change; B2B orders remain VAN ONLY.
- Driver: no public Driver API, Flutter or B2C delivery/collection change; B2C remains DRIVER ONLY, B2C driver does not inherit Van runtime.
- Van: existing runtime resolution excludes invalid legacy B2C and inactive sellers; no JSON shape, navigation, Flutter code, localization labels or new permission; existing empty/no-effective-assignment response is reused. B2B effective stores and null-store representative-only remain supported. Parent #2738 owns broader Van app-device E2E acceptance and real legacy cleanup.
- Backend/Operations: canonical read query filter only; Dashboard assignment forms and write mutations unchanged, pending source reconciliation remains explicit.

No release readiness is asserted. This atomic source guard may close as code-complete only after exact-final-head required checks and verified SHA-guarded merge into Foodex_V2; #2738/#2604/#2503 remain open.

## Existing multi-app token boundary regression (CI rescue)

The initial exact-head Laravel suite (Required CI #43585757547) passed the new five-case Retail seller tests, but two older token-boundary tests expected successful Van login when the only Van assignment was a historically inconsistent B2C Driver link. That expectation conflicts with the new fail-closed B2B seller rule. Retain **the same legacy Retail-linked assignment rows as negative fixtures** and add a separate authorized representative-only Van for users who explicitly possess the VAN_OPERATOR permission. This preserves the positive independent Driver/Van token-boundary tests while asserting the resolver selects only the valid assignment and still preserves the stale Retail row without execution privilege. Scope includes only the fixture blocks in `AuthFoundationTest.php` and `V2CrossAppTokenScopeMatrixTest.php`; no application authorization, route, source migration or production data changed. The full test suite and exact new-head CI must rerun before merge.

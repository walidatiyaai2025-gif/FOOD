# V2 #2731 — MOB-C02 Customer identity / context-selector fail-closed contract

Source: FOODEX unified RBAC/ERP discussion P031/P110/P119; parent #2503. Target `Foodex_V2` only.

## Defect and bounded remediation

The canonical authenticated `StorefrontController::selector()` returns explicit `entitlements`, `wholesale_stores`, `retail_stores`, and `principal_wholesale_store_id`. The Customer `CustomerCommerceContextSwitcher` previously discarded non-map rows through `whereType<Map>()`, silently ignored malformed/duplicate retail IDs and accepted a principal Wholesale marker without an authoritative principal store ID. A partial or contradictory selector response could therefore produce a plausible, locally persisted commerce context even though the authoritative payload was incomplete.

Now malformed selector envelopes/rows, missing entitlements, malformed store IDs, duplicate Retail IDs or missing principal Wholesale ID fail closed with a typed `CustomerCommerceContextSwitchException`. In `switchTo`, validation finishes before `contextStore.write`; no new context is persisted on these failures. Valid selector targets and B2B/Retail route navigation are unchanged, as is token reuse on valid switches. No permissive client-side store entitlement is inferred; backend route/identity/store authorization remains the ultimate authority. Specifically, `retail_context_ids` is **not** used to hide public retail storefronts because it represents account-linked wholesale contexts, not a blanket Retail shopping authorization.

## Impact matrix

- Customer: affected; selector decode/cross-context switch only, no new UI string, AR/EN/RTL/LTR rendering or route changes; existing localized routing preserved.
- Backend/API: server selector response contract unchanged. No endpoint, permission, database or financial mutation; malformed responses now visibly rejected by client.
- Driver: not affected; no shared code/auth/API dependency or dispatch change.
- Van: not affected; no shared code/auth/API dependency or B2B Van routing change.

## Evidence

`customer_commerce_context_switcher_test.dart` covers valid principal/retail switch, forged scope, non-platform identity, missing principal marker, malformed Retail list, missing entitlement/principal ID, and duplicate store context with non-persistence. Required exact-head Flutter analyzer/tests, AR/EN, repo policy, V2 isolation and CI must be green prior to merging; screenshots/device/manual acceptance deferred to #2503. Full MOB-C02 user identity/backend cross-store matrix is not certified by this narrow change, so keep #2731 OPEN for missing end-to-end evidence rather than prematurely closing it.

No main, release, tags, staging or production.

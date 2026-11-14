# V2 P168/P384 — Selected-locale Sidebar labels must not silently fall back

**Issue:** [#3420](https://github.com/walidatiyaai2025-gif/FOOD/issues/3420) · Parent P168 [#2878](https://github.com/walidatiyaai2025-gif/FOOD/issues/2878), P384 [#2904](https://github.com/walidatiyaai2025-gif/FOOD/issues/2904), master #2503 · Integration `Foodex_V2` only.

## Existing source and explicit non-duplication

The prior P168 [PR #2950](https://github.com/walidatiyaai2025-gif/FOOD/pull/2950) has **already merged** `V2SidebarHumanLabelContractTest`: it visits every canonical group/item definition, checks both AR/EN via Laravel `__($key, [], $locale)`, and tests actual role-projected labels and direct-route authorization. P384 [PR #2944](https://github.com/walidatiyaai2025-gif/FOOD/pull/2944) separately standardized seven known Arabic names and tested saved Arabic/English overrides. P381 [PR #3112](https://github.com/walidatiyaai2025-gif/FOOD/pull/3112) verifies menu IA structure and actual routes. **Do not duplicate those tests, change their merged files, or reimplement a second Sidebar registry.**

The **remaining independent flaw** is that `__()` normally permits fallback to the other locale. A newly introduced Sidebar label missing *only* from Arabic can appear in English while `V2SidebarHumanLabelContractTest` passes because the returned label is nonempty and not a machine key. This is explicitly forbidden for system-owned text by `docs/quality/LOCALIZATION_CONTRACT.md`.

## New narrow regression

`V2CanonicalSidebarRegistryLocalizationTest` is configuration-only, with no database, route or mobile fixture. It iterates all current `config('admin_sidebar')` group and item labels (90 definitions at this snapshot; count intentionally not hardcoded). For **each** `ar` and `en`, it calls `Lang::has($key, $locale, false)` and `Lang::get($key, [], $locale, false)` with fallback explicitly disabled, then rejects blank or unresolved/raw translation keys. An AR-only or EN-only missing key must fail even when the opposite locale has a valid label.

The existing P168 test remains the human-readable presentation, role/route, tenant and denial authority. This leaf adds **only** the missing locale-fallback dimension and must not be counted as complete P384 terminology approval, ERP readiness, full 177-item Sidebar acceptance or visual UAT.

## Permission, UI and mobile impact

| Surface | Impact |
| --- | --- |
| Dashboard | New **test-only** strict locale regression for existing Sidebar labels. Desktop/RTL and mobile responsive rendering unchanged; existing bilingual custom overrides preserved |
| Backend/API | No route, controller, security rule, permission, tenant scope, module or API payload change |
| Customer Flutter | Unaffected: no Customer code, locale assets, APIs or deep links changed |
| Driver Flutter | Unaffected: no Driver code, status/dispatch or APIs changed; B2C Driver-only preserved |
| Van Flutter | Unaffected: no Van code, custody/assignment or APIs changed; B2B Van-only preserved |

## Evidence and acceptance

Run `php artisan test --filter=V2CanonicalSidebarRegistryLocalizationTest` from `backend/` in a provisioned Laravel environment. Require the **exact final PR head** to pass Repository Policy, FOODEX V2 Isolation, full applicable Required CI (PHP Syntax/Pint/PHPStan/security, Laravel tests, MySQL/Redis acceptance, AR/EN). GitHub workflow conclusions are evidence; commands listed here alone are not evidence of execution.

**Only** #3420 may close on successful reviewed PR integration to `Foodex_V2`. #2878 remains independently completed; #2904/#2901/#2503 remain open for separate source goals. No main merge, release, tag, cPanel, Setup/Update ZIP production packaging or app distribution.

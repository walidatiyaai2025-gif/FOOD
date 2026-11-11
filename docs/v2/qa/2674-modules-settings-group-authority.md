# V2 #2674 / P344 — Modules & Settings Group Authority

**Source:** FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, paragraph P344 (Administration / الموديولات والإعدادات). **Parent:** #2503. **Isolation:** `Foodex_V2` only; no production, release, tag or `main`.

## Authoritative placement

- **Reuse → Extend → Compose → New Screen:** **Reuse** existing Administration Hub → Settings Center; no separate Sidebar branch, duplicate Module Registry, independent visibility grant, or dead group route.
- **Domain / entity:** Administration → platform settings; registered modules, system updates and protected platform lifecycle/settings controls.
- **Actor / scope:** authenticated platform-wide Super Admin for sensitive launch/reset controls; `platform.manage` for module administration; `system.update` for system update. Store-scoped Retail, B2B and roleless users do not inherit platform authority.
- **Navigation:** `ADMIN_SETTINGS`, through the existing Administration Hub / Settings Center `admin.settings.index` and the canonical `data-settings-group="platform"` heading/tiles. Authorized cards are real links; denied cards show a non-interactive restricted state. The route/controller, not that state, makes the final authorization decision.
- **Destinations owned by this group:** `admin.enterprise-modules.index`, `admin.foodex-reset.index`, `admin.production-dataset-switch.index`, `admin.production-launch.index`, `admin.system-update.index`. Their existing server-side authorization is retained; no new operational capability or deployment action is enabled.
- **Business pattern:** administrative hub/cards, responsive shared mobile-web shell, AR/EN and RTL/LTR, 44px interactive controls, group headings and meaningful restricted states. No new screen or translational key.
- **Entitlements / readiness:** a clickable module registry is not proof that an ERP module is enabled, entitled or certified; existing `ModuleOperationalReadiness` and each business route remain authoritative. No lifecycle mutation, 2nd policy, or permission override is created.

## Code and HTTP evidence

`backend/tests/Feature/V2ModulesSettingsGroupAuthorizedContractTest.php` verifies the real Settings Center output and route authority:

1. A Super Admin in both Arabic/RTL and English/LTR sees the **existing** platform Settings group and the five real, routed controls, plus successfully opens the module registry and update center without making any state-changing request.
2. Restricted FINANCE/B2B_ADMIN actors see non-interactive controls. A roleless user cannot open Settings Center at all (Management Dashboard HTTP 403). All three actor types receive HTTP 403 for direct GET to five protected routes; no registrations/audit mutations.
3. A store-scoped B2C Store Admin does not inherit `platform.manage` from a store role.
4. A temporary synthetic role grant of `platform.manage` makes the Module Control Plane visible and accessible; a user-level explicit global DENY revokes both the tile link and HTTP route. This is a test transaction, never a live role grant.

Adjacent P345 Module Registry leaf #2675 was already implemented/merged in PR #3128; this group-specific contract **does not** modify or duplicate that leaf, module lifecycle code, update package installer, or any active worker-owned file.

## App impact matrix (individual review)

| Surface | Impact | Contract rationale |
| --- | --- | --- |
| Laravel Backend | Test-only change | Existing controller route permissions and database logic remain untouched; authenticated HTTP allow/deny and no-mutation tests added |
| Dashboard | Test-only change | Existing canonical Settings Center/Module Control Plane HTML is exercised; no new Blade/JS/styles/navigation links |
| Customer Flutter | Not affected | No customer auth/API/order/pricing/notification contract changes |
| Driver Flutter | Not affected | No B2C-only driver assignment, wallet, notification, token or route change |
| Van Flutter | Not affected | No B2B-only van visits, routing, stock, offline queue, token or API change |

There is no new mobile-impact child because no application consumer/contract changed. B2B=VAN ONLY, B2C=DRIVER ONLY, RBAC/tenant scope, maker/checker and data protection are unaltered.

## Gates, ownership and handoff

- Owner claim: https://github.com/walidatiyaai2025-gif/FOOD/issues/2674#issuecomment-6787806152.
- Issue-scoped branch: `test/2674-v2-platform-modules-settings-group-authority`; one PR targeting **`Foodex_V2`**.
- Relevant local command: `cd backend && php artisan test --filter=V2ModulesSettingsGroupAuthorizedContractTest`. Exact final HEAD needs GitHub **Repository Policy**, **FOODEX V2 Isolation Gate**, **FOODEX Required CI Gate**, Laravel, AR/EN localization, security and MySQL/Redis install/upgrade/recovery acceptance all green before merge.
- Any uncovered production authorization mismatch must be repaired in this same branch/PR with new tests, not hidden by changing assertions.
- `MANUAL_ACCEPTANCE: DEFERRED_TO_V2_FINAL` under owner decision #2503. No browser/staging/device or production acceptance is claimed from source-only CI.

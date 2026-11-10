# V2 #2776 — P025 registered PLATFORM_OWNER authority

Source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, P025 (AUTH). Parent #2503. Integration target: `Foodex_V2` exclusively.

## Canonical authorization decision

- `PLATFORM_OWNER` is the singular internally provisioned account recorded in `platform_owner_registry(id=1,user_id=...)`, **not** an assignable RBAC badge. The account must also be active with the active global system `PLATFORM_OWNER` role.
- Only the internal `PlatformOwnerProvisioner` writes that registry during bootstrap. Delegated RBAC assignment (including by another SUPER_ADMIN) cannot mint or transfer owner identity.
- The genuine owner is a control-plane principal and gets the configured Laravel Gate permission catalog through the existing `User::hasRole('SUPER_ADMIN')` compatibility path. A user carrying a forged `PLATFORM_OWNER` role without matching registry identity gets **none** of those owner/Super Admin rights from that role.
- The owner's authority is not an override of authentication, active-account checks, scoped record ownership, module/entitlement readiness, finance maker/checker restrictions or immutable audit/ledger controls. The documented canonical server boundaries remain mandatory. In particular, owner status/role mutation through ordinary Security Administration remains forbidden, including requests initiated by a real SUPER_ADMIN.
- Security Center and API are the existing authorized destinations. No new Sidebar route or permission is created; authorization is always evaluated by the server, never inferred from visibility settings.

## Automated acceptance and limits

New `backend/tests/Feature/V2PlatformOwnerExplicitDecisionTest.php` checks:

1. Synthetic unregistered role holders in **both Arabic and English** cannot acquire owner or SUPER_ADMIN identity, including an attempted store-context lookup; direct API Security permissions/roles GET are 403.
2. Genuine provisioned owner has registry identity, configured security authority and a successful protected HTTP Security permission read.
3. A separate real SUPER_ADMIN attempting direct HTTP status deactivation and role replacement on the owner receives 403; the owner stays active and the singular registry row persists.

Existing `PlatformOwnerAuthorityTest` checks the internal provisioning anti-duplication contract and service-level protection; existing role/tenant/entitlement and finance suites remain authoritative for their respective domain boundaries. These tests use seeded, synthetic accounts only; **no production user or source-export account is altered**.

## Surface and mobile impact matrix

| Surface | Impact | Evidence / rule |
|---|---|---|
| Backend/API | Contract coverage, no route or response-schema change | Explicit negative and positive HTTP assertions above; retained Server Gate / registry decision |
| Management Dashboard | No UI source change | Uses existing Security Center and `AdminNavigation` authority; no new label/menu/state or AR/EN string |
| Customer | No Flutter or shared commerce API change | This PR adds only administrative RBAC tests and QA evidence; customer identity/commerce scope unchanged |
| Driver | No Flutter or dispatch API change | B2C=Driver-only invariant untouched; role routing unaffected |
| Van | No Flutter or dispatch API change | B2B=Van-only invariant untouched; owner control-plane logic does not change VAN dispatch |

No frontend RTL/LTR layout is changed. Both locales are exercised for API denial; existing Dashboard locale/accessibility suites remain unchanged. Fresh browser/device/manual acceptance is **DEFERRED_TO_V2_FINAL** under parent #2503, not represented as completed.

## CI and completion

Run focused `php artisan test --filter=V2PlatformOwnerExplicitDecisionTest`, existing `PlatformOwnerAuthorityTest`, Laravel formatting and static checks, and exact-HEAD required Actions (Repository Policy, V2 Isolation, Security, Laravel, Localization). Commit and PR must remain scoped to this issue and merge **only** into `Foodex_V2` when every required check on the final head is green. An unfinished or failing PR is not acceptance; record its exact blocked head/job on #2776. Never merge to `main`, trigger release workflows, or deploy production.

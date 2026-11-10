# V2 #2794 — Conflicting multi-role navigation and HTTP authorization

Source: P042 of FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx; parent #2503; integration target: Foodex_V2 only.

## Authorization rule

Multiple global or store-scoped job roles contribute only their actual active, explicitly granted permissions in the correct business scope. A mixed-role actor does not inherit a new synthetic privilege from the combination. The Sidebar reflects canonical permissions and scoped destinations, but direct HTTP authorization always remains authoritative.

Existing independent controls are preserved: individual explicit DENY overrides, inactive role exclusion, Retail store ownership, B2B/B2C channel restrictions, module/entitlement/readiness gates and platform-owner singleton registration. None is replaced by role-name or Sidebar-only inference.

## Regression matrix

| Scenario | Allowed | Denied and verified |
|---|---|---|
| Global INVENTORY + CUSTOMER_SUPPORT | B2B catalog and customer workspace; AR/EN and RTL/LTR B2B page | Retail channel without store role; Security; System Update; owner-only Sidebar action |
| B2B_ADMIN global + RETAIL_INVENTORY in store A | B2B products and store A inventory, with correctly scoped Sidebar URL | Retail store B (404 anti-enumeration); Security; System Update |
| INVENTORY + B2B_ADMIN, individual catalog.view DENY | B2B dashboard and inventory | Catalog Sidebar/HTTP access, despite two catalog grants; DENY remains stored |
| INVENTORY + CUSTOMER_SUPPORT + inactive security role | Existing B2B dashboard | system.update from inactive role, in Sidebar and direct HTTP |

Each case runs as an authenticated Laravel Feature test against the real management routes, with actual seeded role/permission relationships. It does not invent a bespoke multi-role display rule, impersonation exception, or client-side guard. No privilege mutation or success audit is expected from denied read requests.

## Impact and preservation

- Dashboard: role composition, navigation keys, human-language direction and direct route parity are regression tested. Arabic pages must use RTL and English pages LTR. No new UI element, layout, permission or display rule is introduced.
- ERP: only existing authorized B2B workspaces are displayed. The test changes no ERP, finance, maker/checker approval, delegation or business transaction state; their existing server gates remain binding.
- Tenant/channel/store: mixed B2B and Retail role assignments may expose both authorized channels, but Retail resources remain bound to the assigned store; foreign store URLs remain blocked.
- Module/entitlement/readiness: not bypassed or elevated by combining roles. The tests do not enable modules or change subscriptions.
- Customer App: no customer-facing API route, JWT/session scope or customer model change; no Flutter modification.
- Driver App: no dispatch, driver assignments, GPS or driver API contract change; no Flutter modification.
- Van App: no Van dispatch, territory, route or order fulfillment API change; no Flutter modification.
- Data safety: RefreshDatabase disposable tests only; no migration, production configuration, release artifact, destructive data operation, or rollback requirement in deployed code. Test fixtures are isolated and discarded.

## Acceptance

Focused: cd backend && php artisan test --filter=V2MultiRoleConflictBoundaryTest

CI requires syntax/lint/PHPStan, backend tests, MySQL/Redis acceptance, localization quality, repository policy, exact-head required-ci-gate and V2 isolation. A passing build alone is not release evidence. Merge and close this issue only after checks are green on the final PR commit, and only in Foodex_V2. Never deploy or merge to main.
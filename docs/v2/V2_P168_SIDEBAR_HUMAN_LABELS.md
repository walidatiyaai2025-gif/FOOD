# V2 P168 — Human Sidebar names, internal machine codes

Issue: [#2878](https://github.com/walidatiyaai2025-gif/FOOD/issues/2878). Source: FOODEX Unified RBAC/ERP/Sidebar discussion, legacy P168 (native pointer from source manifest). Parent: #2503.

## Contract and implementation decision

- **Reuse existing behavior:** the authoritative `config/admin_sidebar.php` registry maps internal `item_key` and `group_key` identifiers to localized `label` keys. `AdminNavigation::groupsFor` supplies only authorized routes/permissions. `AdminSidebarManager` may apply explicit bilingual saved display-name overrides without altering authorization.
- Presentation `admin._sidebar` and `admin._sidebar-node` use the active locale's resolved human label, not the machine-readable route, translation key or permission code. Technical identifiers remain in internal configuration and non-visible DOM attributes required for search/selection; administrative diagnostics may expose them explicitly as machine keys.
- **No new renderer, route or group.** Do not overlap active #2892 UI markup, #2676 module readiness, or #2904 naming changes.
- Verification added via `V2SidebarHumanLabelContractTest`: check every canonical registry group's/item's AR/EN key resolution and non-empty human labels, evaluate actual authorized navigation output for SUPER_ADMIN, B2B_ADMIN and CUSTOMER_SERVICE_AGENT in both languages, and prove a no-role user cannot see or directly access catalog/ERP endpoints.
- Test the user-visible label contract rather than invent a second Sidebar authority or bypass existing `AdminNavigation` server access rules.

## Acceptance and dependent impacts

- All names in the current canonical registry must resolve to localized human text, not an untranslated key. The AR/EN catalog parity gate independently detects untranslated/incompatible catalogs.
- Permission, store/tenant, channel, entitlement and operational readiness stay server-authoritative, unchanged by this read-only label test. Existing `AdminNavigationAuthorizationAuditTest` and `AdminSidebarManagementTest` prove store scope, authorization and safe overrides; update tests if future routes change.
- AR/RTL and EN/LTR use the existing responsive Desktop/Tablet/Mobile Web Sidebar. Icons, focus, search, screen-reader and mobile-off-canvas behavior are unmodified. This task adds no UI structure.
- Customer Flutter: not affected; no Customer API, app localization or client state changed.
- Driver Flutter: not affected; no Driver API, app localization or client state changed.
- Van Flutter: not affected; no Van API, app localization or client state changed.
- This is V2-isolated regression acceptance, not proof that all 177 target Sidebar nodes are implemented, nor authorization to enable any unready ERP feature.

Merge gate: exact-head Repository Policy, FOODEX V2 Isolation Gate, Required CI + backend test and localization all green; PR base `Foodex_V2` exclusively. No `main` promotion, release, tag or production deployment.

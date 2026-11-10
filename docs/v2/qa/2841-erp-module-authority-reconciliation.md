# V2 #2841 / P106 — ERP lifecycle, entitlement and operational-readiness reconciliation

**Integration:** Foodex_V2 only. Parent: #2503. Source: P106, FOODEX Unified RBAC/ERP/Sidebar discussion (2026-11-09).

## Canonical authorities (no duplicate enablement switch)

- \`ModuleRegistry::state\` is the persisted enterprise module lifecycle: \`ENABLED\` means the module is installed and administratively switched on. It **does not certify an operable ERP workflow**.
- \`ModuleOperationalReadiness::status\` requires \`ENABLED\`, an explicitly certified **exact manifest version**, and healthy runtime probes; otherwise it returns \`inactive\`, \`uncertified\` or \`unhealthy\`.
- \`ModuleEntitlementResolver::allows\` additionally requires a valid grant for the actual organization/store scope (specific DENY takes precedence). \`ModuleEntitlementManager::set\` audits changes and invalidates the entitlement resolver memo. Entitlement is an access grant, not lifecycle or readiness.
- \`AdminNavigation::routeItemEnterpriseModule\` projects the **intersection** of readiness, entitlement and the existing server-authorized permission/store/channel context. It never grants permission or creates an independently accessible URL.
- A registered Platform Owner may be directed to existing module **diagnostics** when unready; this is not the operational ERP route. Ordinary Super Admin privileges do not bypass readiness/entitlement.
- Real HTTP routes retain the independent middleware/controller/permission/tenant checks, even when a navigation link is present. Sidebar appearance alone never constitutes operational certification.

| Lifecycle | Exact certified version and healthy | Entitled | Route permission/tenant | Staff ERP link |
|---|---|---|---|---|
| Disabled/missing | Any | Any | Any | Hidden |
| ENABLED | No | Yes | Yes | Hidden |
| ENABLED | Yes | No | Yes | Hidden |
| ENABLED | Yes | Yes | No | Hidden |
| ENABLED | Yes | Yes | Yes | Existing real route only |

## Scoped regression

\`backend/tests/Feature/V2ErpModuleAuthorityReconciliationTest.php\` checks the previously separately tested authorities **in one state-transition flow**: ENABLED + entitled but uncertified is hidden; exact-version attestation exposes the canonical route for a permitted role; revoking entitlement immediately hides it without switching off the module; restoring entitlement restores navigation; a stale manifest version hides it; reading Sidebar does not create audit writes. The test exercises AR/EN and RTL/LTR ERP Center HTTP, and rejects a roleless actor's direct procurement URL with HTTP 403 despite module enablement, entitlement and certification.

Existing independent coverage remains authoritative: \`V2SidebarModuleEntitlementAvailabilityTest\` (missing/disabled/entitled/real HTTP), \`V2SidebarOperationalReadinessProjectionTest\` (exact version, health and Platform Owner diagnostics), and \`V2OperationalChannelDenyScopeTest\` (tenant/role isolation). This PR does not change their implementations or replace operational evidence.

## Per-app impact classification

- **Dashboard:** regression coverage only for existing server-authorized ERP Sidebar and HTTP; no Blade/JS/CSS edit or new page.
- **Customer:** no API, payload, role, order or Flutter change; no new app Issue necessary.
- **Driver:** no API, dispatch, status, GPS or Flutter change; no new app Issue necessary.
- **Van:** no API, route, collections, fulfillment or Flutter change; no new app Issue necessary. B2B=VAN ONLY and B2C=DRIVER ONLY remain unchanged.

## Acceptance remaining

Exact-head Repository Policy, FOODEX V2 Isolation Gate, Laravel tests, AR/EN, security and real MySQL/Redis fresh-install/upgrade/recovery must pass before a PR can merge. Independent isolated staging/browser permission-denial, AR RTL/EN LTR, keyboard/mobile accessibility, current ERP workflow health and owner certification are **not** established merely by this regression. Keep #2841 OPEN until the full P106 acceptance is evidenced. No main/release/production operations.

# V2 #2898 / P377 — Finance and procurement operational-readiness gate

**Source:** FOODEX Unified RBAC/ERP/Sidebar Discussion 2026-11-09, P377. **Parent:** #2503. **Integration:** `Foodex_V2` ONLY. **No** `main` integration, releases, tags, APK publication or production deployment.

## Source-to-runtime authority

P377 prohibits showing financial or purchasing ERP operations before their real operational cycle and formal approval are ready. The existing system has one canonical evaluation; this issue adds a focused regression of the **combined** policy rather than introducing an unreviewed second visibility switch.

| Item | Business group | Existing named route | Runtime module | Permission |
| --- | --- | --- | --- | --- |
| `erp_procurement` | `catalog` | `admin.erp.procurement.index` | `procurement` | `procurement.suppliers.view` |
| `erp_finance` | `finance` | `admin.erp.finance.index` | `finance` | `finance.view` |
| `erp_treasury` | `finance` | `admin.erp.treasury.index` | `treasury` | `finance.view` |

- **Domain / users / placement:** B2B wholesale ERP operators use the existing authorized Dashboard Sidebar, classified by business domain. The canonical ERP Center `admin.erp.index` remains a domain hub; no new route or top-level Sidebar section. Decision **Reuse → Extend existing automated acceptance**; no new page, UI control, permissions or module state.
- **Readiness:** `ModuleRegistry` is authoritative for module lifecycle, `ModuleOperationalReadiness` is authoritative for **exact manifest-version certification and healthy probes**, and `ModuleEntitlementResolver` for scoped grants. `AdminNavigation::routeItemEnterpriseModule` projects these and server RBAC/channel/store constraints. A registered Platform Owner may see module *diagnostics* when a module is not ready, never an unready operational destination.
- **Fail-closed:** `ENABLED` alone, an entitlement alone, a stale version certification or a Sidebar layout override is **not** approval to expose procurement, finance or treasury. The real HTTP route's middleware and authorization remain authoritative. Financial SoD and posting permissions are not expanded.
- **Certification boundary:** Test fixtures intentionally attest exact versions **only inside a disposable test**. Production config `enterprise_module_readiness.certified_versions` remains empty unless separately approved with G0–G13 evidence; this regression must not be interpreted as certification.

## Acceptance matrix / evidence scope

New isolated test `backend/tests/Feature/V2ErpFinanceProcurementReadinessGateTest.php` covers:
1. Enabled + platform-entitled modules remain **hidden** to a privileged Dashboard actor until explicitly certified for their exact version; a stale `version + 1` cannot expose them.
2. Arabic RTL and English LTR ERP Center real HTTP render. Sidebar projection has no new audit-log writes.
3. Exact-version approvals reveal only the three **existing real routes** under the canonical `catalog`/`finance` business domains; each returned route is registered.
4. Revoking the `finance` entitlement removes the finance link while independent approved procurement remains available; entitlement revocation is audited.
5. Roleless actors and B2C store-scoped Dashboard administrators cannot acquire wholesale ERP permissions from enabled/certified modules. Their direct procurement, finance and treasury HTTP routes must return **403** in Arabic and English.

**Security/risk:** A wrong-side 200, cross-store grant, module bypass or false-ready link fails the test. No production accounts, data, financial ledgers, migrations, operational certification or shared Sidebar runtime files are changed. Rollback of this test-only contribution deletes its two newly added files; no database rollback is needed. Existing financial maker/checker contracts remain untouched.

## Customer / Driver / Van impact, separately

- **Customer app:** NOT AFFECTED: no Flutter, customer API, auth payload, order, price, cart, routing, or deep-link changes. Server-only Dashboard regression tests assert there is no new permission grant.
- **Driver app:** NOT AFFECTED: no Flutter, driver API, delivery, location, dispatch, collection or status contract change. B2C remains **Driver ONLY**.
- **Van app:** NOT AFFECTED: no Flutter, Van API, settlement, visits, routes or dispatch contract change. B2B remains **Van ONLY**.

No new mobile Issue is warranted for this test/document-only change. It does **not** substitute for real runtime cross-app certification of broader V2 features.

## Definition of done and outstanding gates

The issue may be integrated **only** through one issue-scoped PR with `base.ref=Foodex_V2`, current exact-head Repository Policy, FOODEX V2 Isolation Gate, Required CI (PHP syntax/Pint/PHPStan/security/OpenAPI, AR/EN, Laravel suite, real MySQL/Redis install/upgrade/recovery) and all required child jobs GREEN. Record Git SHA and GitHub Actions evidence on Issue #2898.

**Keep Issue OPEN after code integration** until independently verified isolated staging/browser checks have proven authorized B2B and denied B2C/roleless ERP navigation and routes at 320/360/390/412/430/768/1024/1280px, AR RTL/EN LTR, keyboard/screen-reader/focus and real operational-cycle acceptance; neither CI nor static files prove that browser evidence. Never promote V2 to `main` or production without a new explicit owner authorization.

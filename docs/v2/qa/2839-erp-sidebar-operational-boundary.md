# V2 #2839 — ERP Sidebar is not an operational readiness grant

- Source: FOODEX Unified RBAC/ERP/Sidebar discussion, table row `T104R02`.
- Parent issue: #2503. One issue branch/PR, integration solely into `Foodex_V2`.
- No `main` merge, production deployment, release artifact or tag authorized.

## Canonical authority and placement

The existing `admin.erp.index` is an informational Dashboard entry point (Reuse). No new Sidebar node or page is added. Its module cards and the existing `ModuleOperationalReadiness` diagnostic distinguish ENABLED lifecycle status from certified readiness.

The new `EnsureErpOperationalReadiness` middleware is attached to the existing authenticated Management Dashboard route group; it passes through all non-operational routes and enforces fail-closed HTTP 403 for the following named operational route families:

| ERP domain | Route namespace | Authoritative module code |
| --- | --- | --- |
| Procurement | `admin.erp.procurement.*` | `procurement` |
| WMS | `admin.erp.wms.*` | `inventory_wms` |
| Finance | `admin.erp.finance.*` | `finance` |
| Treasury | `admin.erp.treasury.*` | `treasury` |
| BI | `admin.erp.bi`, `admin.erp.bi.*` | `enterprise_bi` |

A direct request requires **ENABLED + exact certified manifest version + healthy probes**. This is additional to, never a replacement for, existing controller role/action/permission, entitlement, scoped store/channel and financial maker-checker protections. Changing Sidebar layout, labels or URL cannot bypass the server gate. Invalid module registration or health evaluation fails closed. Default `certified_versions` remains empty: no runtime certification is inferred or silently added.

`admin.erp.customer-service*` remains under its existing dedicated customer-service permission and scope contract rather than mapping it artificially to one of the five ERP modules. Any further readiness requirement for this workflow must have its own canonical authority. The ERP Center itself remains readable to authorized users and presents blocked modules informationally.

## Test plan and acceptance limitations

`backend/tests/Feature/V2ErpSidebarOperationalBoundaryTest.php` covers enabled + entitled but uncertified Procurement, direct GET/POST and wrong-version denial, cross-module direct denials, ERP Center informational behavior and route middleware coverage. Existing domain-specific Laravel tests remain responsible for allowed-role, tenant/store/channel, maker-checker and data-scope authorization after the gate.

- Dashboard: only server-side module readiness interception; no template, stylesheet, route names or navigation keys changed. AR/EN and RTL/LTR use the existing localized ERP Center; HTTP 403 is locale-independent.
- Customer app: no API v1, identity, commerce or Flutter source changes.
- Driver app: no API v1, fulfillment or Flutter source changes; B2C Driver-only unchanged.
- Van app: no API v1, fulfillment or Flutter source changes; B2B Van-only unchanged.
- Installation/update: no dependencies, migrations or assets changed. Readiness certificates must be issued only after version-specific acceptance; ZIP release is **not** authorized by this PR.

**Must remain open until:** the latest exact-head Required CI, V2 Isolation, Backend, Security, localization and MySQL/Redis checks are green; independent staging verifies a certified healthy role-scoped route and denied direct requests for unauthorized/disabled/unready contexts. The PR alone cannot certify that ERP G0–G13 transactional end-to-end paths are production ready.

# FOODEX V2 — T122R6 / #2686 — Customer Service Supervisor

**Integration only:** `Foodex_V2`. Master #2503. Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx` / row T122R6 (`CUSTOMER_SERVICE_SUPERVISOR`). No main, release, tags or deployment.

## De-duplication and canonical scope

- The role is already seeded in `backend/config/job_roles.php` as a global, tenant-scoped customer-service supervisor, with B2B channel admission but no global Retail authorization. Do not create another role, permission system, Sidebar tree or customer-service workspace.
- Existing V2 #2770/#2916 regression `V2CustomerServiceSupervisorAuthorizationAuditTest.php` already validates the **exact seeded grant set, role metadata, AR/EN presentation, B2B/B2C tenant role scope, inventory DENY, inactive user and finance/security exclusion**. The existing `CustomerServiceManagerWorkspaceTest.php`, `V2CustomerServiceSecurityBoundaryTest.php` and related source tests cover general customer-service navigation/catalog/WhatsApp structure. Those are **not re-implemented here**.
- New incremental gap for #2686 is the *real authenticated HTTP supervisor journey* and privilege separation between observing WhatsApp agents and mutating staff identity/team membership.

## Implemented tests and expected security contract

`backend/tests/Feature/V2CustomerServiceSupervisorAuthorityTest.php` exercises the existing routes, role, store and policy services under a seeded transient MySQL/SQLite test DB:

1. **Real positive HTTP**: active supervisor can visit the canonical Wholesale Customer Service workspace (`admin.erp.customer-service`) and WhatsApp Supervisor analytics (`admin.whatsapp-supervisor.index`) in **Arabic RTL** and **English LTR**, with the WhatsApp `agent_inbox` capability explicitly enabled in a test-only local module state.
2. **Tenant isolation at both direct URLs**: a global Wholesale supervisor cannot select a foreign active Retail store by `?store_id=...` on either workspace; both respond `404`. Canonical `OperationalTenantScope` and `CustomerServiceCaseManager` independently exclude that store, not merely Sidebar hiding.
3. **Explicit privilege revocation**: a global per-user `DENY whatsapp.customer_service.supervise` blocks actual WhatsApp Supervisor analytics (`403`) while ordinary authorized Customer Service workspace remains usable; role and other permissions are preserved.
4. **Separate staff identity maker authority**: WhatsApp `whatsapp.agent_status.manage` and supervisory access are *not* `customer_service.team.manage`. Attempts to create a new Customer Service Supervisor or edit/deactivate an existing agent through protected team POST/PATCH return `403` with zero persisted user/role changes.

No test data contains actual users or production credentials. No behavior is inferred from a screenshot, mock response or mere navigation label. No new controller/route/action permission is introduced, and no financial maker/checker or SoD grant can be inferred from the supervisor role.

## Impact classification

| Surface | Effect |
| --- | --- |
| Laravel Backend / Dashboard | A dedicated **new** role-specific HTTP authority test; real existing Customer Service and WhatsApp Supervisor routes, team service, existing bilingual RTL/LTR UI. No production source changes. |
| Customer Flutter | **Unaffected**: no app, Customer API, customer identity, credit, cart, notification or store switching contract modified. |
| Driver Flutter | **Unaffected**: no dispatch API, tokens, route, wallet, proof or Flutter change. B2C is **DRIVER ONLY**. |
| Van Flutter | **Unaffected**: no Van assignment, territory, visit, settlement, token or Flutter change. B2B is **VAN ONLY**. |

## CI, acceptance and owner fence

Run `php artisan test --filter=V2CustomerServiceSupervisorAuthorityTest` within the exact-head `FOODEX Required CI Gate`. Require **Repository Policy**, **FOODEX V2 Isolation**, PHP syntax/Pint/PHPStan/security/OpenAPI, AR/EN, Laravel suite, MySQL/Redis real install/upgrade/recovery, and terminal `required-ci-gate` all green.

Manual full-browser/mobile-web accessibility, campaign delivery consent, and actual production account/WhatsApp network runtime cannot be certified by these tests and remain with V2 final gates #2503/#2750 and dedicated CRM/production owners. This bounded PR does not claim the entire T122R6 source acceptance solely because CI is green; preserve issue OPEN if such evidence is outstanding. Do not merge an observed security regression merely to force green tests.

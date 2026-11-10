# V2 P108 — ERP runtime inventory (isolated evidence)

Issue: #2842, source P108, parent #2503. Integration is **Foodex_V2 only**. This document is NOT an ERP launch certificate, a production deployment record, or permission to promote V2 to main.

## Provided inventory

The internal Laravel console command **foodex:v2-erp-inventory** performs a read-only snapshot of the Laravel process in which it runs, using registered routes and project files:

- Root VERSION; PHP runtime version; Laravel version; SHA-256 of backend Composer manifest.
- Declared production Composer constraints and the versions Composer reports as installed in the executing runtime. Missing installed packages are null, never silently certified.
- Total routes and ERP/B2B/wholesale/finance-related route subset matched by static URI/name/controller-action keywords; methods, routes, channel hints and known middleware/permission signals.
- Deterministic SHA-256 of route subset, suitable for controlled staging comparison.

**No database read or write, no network call, no user record, no environment dump, no filesystem write, no token, no credentials, no request parameters.** The command is not exposed as a web endpoint; the output must remain private to authorized CI/review staff. Do not publish live server route topology.

An authorized staging/CI runner can invoke this command from backend:

    php artisan foodex:v2-erp-inventory

This is *not* a requirement for cPanel end users to use SSH or Terminal when installing/updating; the Web Installer/Admin Updater remain mandatory.

## Boundaries and follow-up evidence

The snapshot is **observational**, not security certification. A route with a can: middleware is not thereby proven accessible or secure; a route with no listed middleware is not thereby proven unprotected, as controller checks and dynamic guards may exist. B2B/B2C channel classification is only a name/path hint. The command neither queries effective RBAC permissions nor modules, entitlements, health, tenant data or business records.

| Dimension | Evidence available | Remaining independent gate |
| --- | --- | --- |
| Server version | Reads runtime checkout VERSION and PHP/Laravel version | Run on approved isolated staging source SHA; verify deployed artifact provenance. Production cannot be inspected/deployed as part of V2. |
| Wholesale scope | Candidate route inventory | Reconcile B2B/retail shared routes and controller-internal access checks; prove tenant/store separation. |
| Route authorization | Whitelisted middleware and declared can: permission tokens | Exercise allowed/denied direct requests, per-user overrides, entitlement and readiness gates, all role and channel scopes. |
| ERP readiness | Always unverified | Complete #2838–#2841 plus workflow, data and ERP operational certification; ENABLED or a visible Sidebar is insufficient. |
| Dependencies | Composer constraints, observed installed versions and manifest SHA-256 | Verify installed versions against the signed deployment artifact, migrations, compiled frontend assets, database and Redis health, signed Setup/Update provenance. |
| Customer app | No contract change | No impact for this console-only diagnostic; review again for subsequent API changes. |
| Driver app | No contract change | No impact for this console-only diagnostic; B2C Driver policy unchanged. |
| Van app | No contract change | No impact for this console-only diagnostic; B2B Van policy unchanged. |

## Tests and exit criteria

The focused V2ErpRuntimeInventoryTest verifies parseable JSON, manifest/runtime/installed-package facts, protected and unprotected synthetic route handling, explicit non-certification of RBAC/tenant/entitlement/readiness, and non-disclosure of environment secrets. Green CI on the command itself is not evidence that ERP works end-to-end.

The JSON intentionally reports **production_deployment_verified=false**, **environment_identity=not_attested**, and effective role permissions, tenant/store isolation, module entitlements and ERP readiness as **not_tested**. This avoids silently converting discoverability into access approval.

After reviewed PR integration to Foodex_V2, keep #2842 OPEN until a privacy-reviewed isolated staging runtime report, matched source provenance, route-level authorization/tenant tests, production-dependency comparison and version-scope acceptance are recorded. No main promotion, release, live server update, or owner approval is implied.

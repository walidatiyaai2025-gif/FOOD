# V2 #2777 — P026 code-versus-runtime Sidebar inventory (read-only)

## Purpose and source

- Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P026 (actual code/production menu reconciliation before diagnosing hidden or denied entries).
- Parent: #2503; exclusive integration target: `Foodex_V2`. Implementation is strictly additive to the existing `AdminSidebarManager::registry()` and `layoutData()`; **no new UI, route, access gate, permission role, or deployment**.
- Audit location: existing Security/Admin Sidebar configuration remains canonical; this CLI produces evidence for a privileged operator's **separately authorized** runtime snapshot. Do not mistake a menu/display preference for authorization.

## Snapshot workflow and interpretation

On an authorized isolated V2 instance, an operator or CI test environment can capture JSON using `php artisan foodex:v2-navigation-snapshot`. To compare a second, independently captured authorized inventory JSON with the current instance, use `php artisan foodex:v2-navigation-snapshot --against=/secure/path/previous-snapshot.json`. Never upload internal deployment data into a public issue.

The snapshot contains only:
- Canonical registered group/item keys and declarative label keys, scopes, channels, module identifiers and permission tokens;
- Locally stored Sidebar *presentation structure* (enabled, sort order, parent and group references) without translated custom text or per-role visibility rows;
- Deterministic SHA-256 digests of the redacted maps and a numeric diff of added, removed and changed entries.

The command **never** reads accounts, role assignments, credentials, user-specific labels, individual role visibility, `.env` or configuration secrets; performs no DB mutation or permission grants. It rejects missing/symlinked/oversize/malformed comparison files and returns nonzero on drift or incomplete saved-layout inventory.

| Comparison status | Meaning |
| --- | --- |
| `not_compared` | Only current local snapshot was captured |
| `matching_snapshots_unattested` | Both sanitized representations match; **not** a production or authorization attestation |
| `drift_detected` | Some registered or saved presentation metadata differ; investigate before diagnosing an access complaint |
| `incomplete_runtime_inventory` | At least one inventory lacked the complete saved-layout tables; fail closed |
| `invalid_baseline` | No valid comparable reference supplied; fail closed |

All outputs intentionally state `production_deployment_verified=false`, `environment_identity=not_attested` and authorization `not_tested`. SHA-256 is a fingerprint, **not** a signature or proof that the other snapshot came from production. No external deployment is queried or changed. A separate independently signed/controlled environment identity, current deployed commit SHA, authorized user/role/store/channel and real route-response proof are required before declaring actual code-to-production parity. Saved hiding alone must not be diagnosed as HTTP 403 or vice versa.

## Acceptance and security evidence

Tests in `backend/tests/Feature/V2NavigationSnapshotTest.php` cover deterministic read-only output, saved local presentation flags, Arabic/English custom-label redaction, a matching-but-unattested comparison, mismatched canonical and persisted layout despite stale hashes, and fail-closed malformed/incomplete inputs. Exact-head Laravel, Pint/PHPStan, AR/EN, Repository Policy, V2 Isolation and MySQL/Redis acceptance runs on the PR must be verified. Runtime authorization, cross-tenant, module readiness and route HTTP checks remain separately covered by canonical server policies and existing V2 integration tests; this diagnostic does **not** create a second authority.

## Surface impact classification

| Surface | Classification | Evidence and reason |
| --- | --- | --- |
| Backend | Affected: read-only Artisan diagnostic + feature tests only | No HTTP/API, authorization, data migration, seed or stored user mutation |
| Dashboard | No runtime UI impact | No controller, Blade, `AdminNavigation`, Sidebar Manager or config change; existing admin interfaces retained |
| Customer | No impact | No Flutter files, login/commerce API payloads or customer-specific permission contract changed |
| Driver | No impact | No Flutter files, dispatch, GPS, driver-role or HTTP contract changed |
| Van | No impact | No Flutter files, B2B VAN ONLY fulfillment, visit, assignment or API contract changed |

**Issue disposition:** Keep #2777 OPEN even after a green PR is merged until an authorized comparison of the actual deployed production menu with the code/current deployed SHA, effective role/tenant authorization and original P026 evidence is independently reviewed. Manual V2 UAT remains deferred under #2503. No `main` integration, releases, tags, cPanel updates or production execution are authorized.

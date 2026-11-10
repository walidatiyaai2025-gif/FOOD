# V2 #2863 — P141 ALLOW is not an authorization bypass

**Integration:** `Foodex_V2` only; no `main`, tag, release or production action.

## Scope / authoritative code

- **Security**: `User::hasPermission`, `User::effectivePermissionCodes`, `OperationalTenantScope`, canonical Security permission override route, and `EnterpriseProcurementController` module-readiness gate. No client-side role names or Sidebar check can grant access.
- **Existing routes**: `/admin/b2c/orders`, `/admin/b2b/orders`, `/admin/erp/procurement`, and the existing Security user-permissions mutation endpoint.
- **Threat model**: deliberately seed even forged/stale ALLOW rows in test database to prove fail-closed *read-side* behavior, as well as a genuine unauthorized PUT rejected on the server. Seeded rows are not normal approved user-grant workflow.
- **Valid procurement test context:** provision the authoritative organization/company/legal-entity hierarchy for the B2B store, and supply its exact `store_id` to the real route; missing enterprise scope independently returns HTTP 409 and is not permission evidence.

## Regression matrix

| Scenario | Expected outcome |
| --- | --- |
| Retail store A role; forged ALLOW for retail store B | A remains authorized, B is denied at User permission and real B2C orders route |
| Retail store actor; global ALLOW for wholesale B2B | Wholesale permission and B2B orders route denied |
| Mixed global wholesale + assigned retail store roles, wholesale global ALLOW | Wholesale context accepted; other retail stores and B2C-only global permission rejected |
| Procurement module disabled with active role and global ALLOW | Real procurement HTTP route fails closed (404); later enabled module route recovers |
| B2B admin attempts to write user ALLOW without security authorization | Real HTTP endpoint rejects (403); no override stored |
| AR/EN | Security and disabled-module denial tested under both locales |

## Cross-app impact

- **Customer**: no API, database schema, runtime or UI change.
- **Driver**: no API, dispatch, scope or Flutter change; Driver B2C-only restrictions retained.
- **Van**: no API, dispatch, scope or Flutter change; B2B Van-only restrictions retained.
- No linked mobile implementation issue is necessary for this regression-only scope.

## Verification gate

`php artisan test --filter=V2AllowScopeReadinessRegressionTest` plus full exact-head Required CI; issue stays open until tests pass, PR merged to `Foodex_V2`, and integrated SHA verified. Failure requires fixing the existing canonical service or route guard on the SAME branch/PR, never weakening acceptance assertions.
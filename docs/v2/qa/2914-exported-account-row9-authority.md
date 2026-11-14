# V2 #2914 — historical Table 5 row 9, roleless identity boundary

Source: `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, **native Table 5 data row 9**. Parent #2503. This audit is isolated to `Foodex_V2`.

## Historical observation and confidential identity protection

The archived export shows **no assigned Dashboard RBAC role** and **zero inherited role-permission codes** for row 9 *at export time*. This does not prove the current account's type, active status, actual store/tenant, permission overrides, customer/Driver/Van registration or effective access. In particular it does **not** authorize account deletion, disability, elevation or role assignment.

The source's historical username/handle is intentionally **not copied** to this repo, code fixture, test output, PR or public Issues. No production database or live user was queried or mutated for this PR. The independent private read-only correlation is still required.

## Distinct synthetic contract covered

`backend/tests/Feature/V2ExportedAccountRow9AuthorizationTest.php` exercises two separate possible classifications using fabricated identities. No test claims to represent the source account:

1. A **legitimate B2C Customer** who has a real Customer and B2cCustomer profile but zero Dashboard RBAC roles can read **its own** existing Customer profile in both Arabic and English. The absence of admin roles must not automatically disable/delete this identity or break the valid Customer profile.
2. That same Customer cannot access the Security Center, Sidebar role management or either B2B/B2C administrative Orders route. The canonical Sidebar is empty and B2B/B2C operational `orders.view` scopes remain empty.
3. A **separate unclassified identity** with no customer record can still inspect the basic identity profile without being fabricated into a Customer; customer-owned resources are denied, and the user gains no Dashboard navigation/role.
4. Fixture accounts remain active and no role pivots, scoped role assignments or overrides are created. We never infer `PLATFORM_OWNER`, `SUPER_ADMIN` or mobile role from a login field or from absence of roles.

This is a specific **positive Customer continuity + negative administrative authorization** contract. It does not duplicate the neighboring row-13 Driver/Van-token denial, row-17 B2B/B2C administrative order-write coverage or row-18 Security inventory classification test. All HTTP authority is exercised through existing routes and real Laravel test requests, not by hiding a UI item.

## Scope and cross-app impact matrix

| Surface | Classification |
| --- | --- |
| Backend | Two new synthetic feature tests; no controller, permission, schema or public API change |
| Dashboard | Existing server-authorized navigation and direct security/order routes under AR/EN; no new page, key or UI primitive |
| Customer | **Test-only positive existing** `GET /api/v1/profile` for a legitimate Customer. No Customer Flutter, authentication, API payload or workflow change; mobile integration issue not required for this delta |
| Driver | No Driver controller, credentials, API or Flutter change; B2C=DRIVER ONLY remains |
| Van | No Van controller, assignment, API or Flutter change; B2B=VAN ONLY remains |
| AR/EN, RTL/LTR | Existing responses exercised in both locales; no presentation markup was changed |
| Privacy/data | Disposable factories and database; no current-identity queries, live mutation, deletion or confidential names |

## Outstanding exact-account acceptance — keep Issue OPEN

A separate explicitly authorized **least-privilege read-only reviewer** must privately correlate the historical row-9 record with the *current* identity and determine:
- Dashboard / Customer / Driver / Van classification and active state;
- effective global/store roles and store, legal-entity, company, B2B/B2C scope;
- inherited permissions versus explicit `ALLOW`/`DENY` overrides, and any conflicting roles;
- true server-side direct-route decision versus the Sidebar projection;
- module entitlement/readiness, mobile session eligibility, tenant isolation and sensitive SoD intersections;
- sanitized remediation decision, if any, without disclosing identity details in a public repository.

If that evidence proves a runtime contract gap, use a separately reviewed V2 implementation scope (or the existing issue branch/PR as governance requires), and link the corresponding actual mobile app Issue if its public API changes. Synthetic CI alone is **not** a historical account audit or feature acceptance.

## Delivery gate

One issue branch `test/2914-v2-exported-account-row9-scope-audit`, one PR with **base `Foodex_V2` only**. Before merging any bounded code, all applicable **latest exact-head** Repository Policy, FOODEX V2 Isolation and FOODEX Required CI Gate (Laravel, PHP/Pint/PHPStan/security, localization and relevant MySQL/Redis acceptance) must succeed. When technically integrated, keep #2914 open pending private account-specific evidence; manual real-device/browser final UAT under #2503 is separately deferred, not a CI waiver.

No `main` integration, tags, release publication, production/cPanel deployment or live-user mutation.

# V2 P341 — Approval Limits authority and isolation (Issue #2671)

## Source, route and placement
- Source: FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx, P341 (Administration > Approval Limits); parent #2503.
- Existing canonical route: `GET /admin/security/enterprise`, in the Security Center Administration Hub. Existing request endpoint: `POST /admin/security/enterprise/approval-limits`; revoke: `DELETE /admin/security/enterprise/approval-limits/{approvalLimit}`. Existing independent checker action: `POST /admin/security/enterprise/changes/{workflowRequest}/decision`.
- Placement: **Reuse** existing Administration > Security Center > Enterprise Security approval limits card; no new Sidebar item or screen. Permitted administrators reach it via existing Security Center. Permission-driven controls and 15-row paginator are preserved. Same responsive Blade structure and existing translated AR/EN labels; no new raw user-facing strings.
- Roles: `security.view` permits the page, `security.approval_limits.manage` permits submitting/revoking in an allowed scope, `security.approve` permits a distinct checker. The request is not an applied monetary limit until approval. Both permissions and the enterprise scope resolver must allow the actor and target scope; URL IDs never substitute for scope authority.

## Proven defect and correction
1. Prior GET supplied every `enterprise_approval_limits` record to any `security.view` actor, even when enterprise role data scopes restricted the actor to one organization. Under P341, that is an information-disclosure and scope-projection inconsistency.
2. Prior direct POST/DELETE relied solely on a global `Gate` followed by a service validation error for out-of-scope actions; direct hostile HTTP requests received a form validation redirect instead of the required fail-closed 403. The service still protected persistent changes, but the endpoint authorization contract was inconsistent.
3. The existing scope resolver now filters selectable/visible scopes and approval-limit page rows. Approval-limit POST and DELETE enforce exact target-scope authority **before** the security manager enqueues an independent-checker change. The business service keeps its existing role/permission, active-scope, currency, three-decimal amount, maker/checker and audit validations. Missing records still use normal 404 model binding. No schema or release changes.

## Automated evidence
- `backend/tests/Feature/V2ApprovalLimitsAuthorityTest.php`: two scoped roles on the same active operator (read and manage), organizational A vs B, AR RTL/EN LTR real page GET, denied foreign data presence, denied foreign create and direct-ID revoke 403, no workflow side effect, allowed own-org request, no mutation before separate approval, maker denied self-approval, independent checker applies once, audit record, foreign limit unchanged, roleless GET/POST 403.
- Existing `backend/tests/Feature/EnterpriseSecurityAdministrationTest.php` covers canonical workflow monetary limit resolution, expiry on delegations, and scoped permission behavior. No new source or client API contract.
- Required premerge gates: branch policy, Laravel full/focused tests, Pint/PHPStan, AR/EN localization, V2 Isolation Gate, exact-head FOODEX Required CI and required child jobs. **Do not claim these passed until GitHub reports green on final head.**

## Surfaces and impacts
| Surface | Result |
|---|---|
| Backend | **Affected**: Server-side scope filtering and 403 enforcement for the existing Dashboard HTTP endpoints. |
| Dashboard | **Affected**: Existing Enterprise Security Center P341 list and allowed action projection; no new UI markup or styles. Existing AR/EN and responsive layout preserved. |
| Customer app | **Not affected**: No Customer API, identity, customer pricing, order or app source changed. |
| Driver app | **Not affected**: No Driver API/dispatch/settlement lifecycle changed; B2C remains Driver only. |
| Van app | **Not affected**: No Van API/territory/visit/sales lifecycle changed; B2B remains Van only. |

## Isolation and acceptance
- Branch `fix/2671-v2-approval-limit-scope-integrity`; integration PR base **Foodex_V2 only**, never main.
- Manual responsive-browser AR/EN and real persona UAT is deferred to the final V2 program gate #2750 per owner decision on #2503. It is not claimed performed by this code change.
- No direct production account or monetary-limit mutation, no release/tag/deployment, and no changes to existing secret/stateful configuration.

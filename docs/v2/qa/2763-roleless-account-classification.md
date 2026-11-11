# V2 #2763 — roleless identity classification (T015R05)

## Source, placement and authority
- Source: unified RBAC/ERP/Sidebar source row T015R05, parent #2503; **Foodex_V2 only**.
- Existing domain/entity: Administration → Security & Users → User access.
- Placement: **EXTEND** canonical `admin.security.index` / `SecurityController@index`; existing Administration Hub entry, no new Sidebar route or parallel RBAC engine.
- Actors: `security.view` authorization at the HTTP boundary. Role grants/status changes remain exclusively controlled by existing `users.roles.manage` / `users.status.manage` server mutations. Filter is read-only.
- UX: `assignment=roleless` applies **both** `whereDoesntHave(roles)` and `whereDoesntHave(storeRoleAssignments)`, combines with existing search/status and uses existing pagination. Existing UI table, labels, EN/LTR and AR/RTL, native filter and contained mobile horizontal scrolling are reused.
- Classification: `is_platform_customer=true` marks a **platform customer identity** where admin RBAC assignments are not required. Remaining unassigned accounts are **review-required**, not automatically defective or necessarily employees. Neither class implies any grant, deletion, audit mutation or permission bypass.
- A roleless actor cannot view the Security Center (`security.view` 403); implicit Platform Owner/Super Admin status is never inferred from flag, name or missing role.

## Data boundaries and verification
- No production/user identity data is exported or recorded in this PR. Historical **seven** is a source snapshot, not a hard-coded production invariant: the current count is server-derived, searchable and pageable, and may differ on another installation.
- The server never bulk-assigns, activates, deactivates, deletes or migrates a roleless identity during classification. The administrator must review non-customer identities against authoritative account records before a separate approved lifecycle action. No automatic role grant is proposed.
- No new entitlement/ERP/finance authority is created: existing allow/deny, scope, module-readiness, maker/checker and SoD gates remain upstream and unchanged.
- Production snapshot reconciliation (which of the historical seven users currently exist and their account provenance) requires authorized account access. This change does not claim that review has happened.

## Automated security / API / mobile impact
- `V2RolelessAccountClassificationTest`: seven synthetic roleless users, customer/review classification, missing role + store-role assignment persistence, no deleted users, search/status/invalid-filter behavior, Arabic RTL, English LTR and unauthorized direct request 403.
- **Customer:** existing `is_platform_customer` identity is read-only; no Customer API, login, account, order, pricing or notification contract change.
- **Driver:** no driver APIs, dispatch, wallet, session or role-policy change; roleless driver is not given a new permission.
- **Van:** no Van APIs, assignment, trip, offline, sales or role-policy change; no B2B→Driver fallback.
- No migrations, data rewrites, live release, tag or production deployment. Manual V2 final UX/UAT acceptance is deferred per parent #2503.

## Resume / acceptance
- Run `cd backend && php artisan test --filter=V2RolelessAccountClassificationTest`, targeted Pint, static analysis and exact PR head required CI.
- UI structure/a11y: same authorized Administration Hub route; labeled select, native keyboard semantics, localized labels, no new visual primitives, inherited compact mobile layout/RTL behavior.
- Final legacy dataset review (seven specific identities) remains an authorized separate operational verification if owner requires matching historical source row to current users. No confidential usernames or production account evidence belong in public Issues/PRs.

# FOODEX V2 #2661 / P331 — Users & Permissions (إدارة المستخدمين والصلاحيات)

## Source, authority and placement
- **Source:** FOODEX Unified RBAC / ERP / Sidebar Discussion (2026-11-09), P331; parent #2503. Integration target **Foodex_V2 ONLY**.
- **Business domain / entity:** Administration & Security / users, roles and permissions.
- **Actor / goal:** authorized Super Admin or specifically delegated security operator reviews users and their effective access without inheriting the platform-owner role.
- **Workflow / frequency / density:** recurring security review, Users list and audit trail; existing dense, paginated Security Center.
- **Existing parent and route:** Administration Sidebar group `admin.administration.index` -> **Users & Permissions** card -> `admin.security.index?section=users`; audit link uses `?section=audit`.
- **Placement:** **Reuse**, not a new independent Sidebar group or unguarded route. Navigation levels `SIDEBAR → DOMAIN_HUB → PAGE_TAB`. The P331 group intent is fulfilled by the existing discoverable composite; adding another direct users-permissions Sidebar menu entry would duplicate #2660/#2662.
- **Navigation ownership / active context:** existing AdminNavigation group and AdministrationHubController; SecurityController enforces the server-side `security.view` gate. This hub card is always described but its actions are replaced by restricted text without authorization; hiding a link is not the security boundary.
- **Fallback / permission:** denied direct HTTP request returns 403. Users' individual modifications remain separately permission-gated; read permission never grants create, approval, role replacement, or treasury access.
- **Desktop/tablet/mobile web and AR/EN:** reused responsive Hub/center with directionally correct Arabic RTL and English LTR, localized title/tabs, standard focusable anchors and existing a11y semantics. No changed markup or screenshots claimed.
- **Master Data / inputs:** existing role and account lookup and scoped assignment sources; no raw technical ID/JSON fields added.

## Focused executable evidence
`backend/tests/Feature/V2UsersPermissionsHubAuthorityTest.php` adds isolated Laravel feature checks for:
1. Authenticated Super Admin can discover the canonical group and real Security Center Users/Audit links; AR and EN render correct direction and localized copy; the Users tab GET is authorized.
2. A global `roles.manage` actor with an additional unrelated `catalog.view` role still cannot obtain `security.view`: the Hub renders **without** the Users & Permissions action links, and direct Users/Audit GET returns 403. This checks multiple-role additive behavior without inventing a grant or using visual hiding as authorization.
3. A roleless platform customer sees no Administration Hub item; direct Users GET and user-create POST return 403, and no user record is inserted.

## Scope fences and further gates
- This implementation owns **only** the two new #2661 test/QA paths; no runtime routes, controllers, view, Sidebar, permission policy, DB schema, finance, store assignments, or releases changed.
- Existing #2660 owns Administration group test acceptance, #2662 owns Users destination, #2663 owns authorized create flow. Preserve these issue/branch boundaries and their upstream proofs.
- P331's aggregate cross-store, explicit ALLOW/DENY overrides, user-role assignment mutation and disabled-module tests must be reconciled with their canonical issue-owned regressions; this small patch is **not** a claim that every related V2 RBAC feature or ERP module is certified. Record any unproved source row as OPEN rather than make a fictitious production attestation.
- **Customer:** no mobile customer code/API changes, platform-customer denial explicitly tested.
- **Driver:** no B2C dispatch, assignment, authentication, notifications or Flutter changes.
- **Van:** no B2B visits, route, stock, finance, authentication or Flutter changes.
- Existing invariants B2B=VAN ONLY and B2C=DRIVER ONLY are untouched.

## Acceptance / delivery evidence
After PR creation, record its exact final head SHA, base=Foodex_V2, Repository Policy, V2 Isolation, Required CI and all required child-job conclusions (backend syntax/Pint/PHPStan/security, Laravel full tests, AR/EN, real MySQL/Redis install/upgrade/recovery) and final merge verification. No local PHP/Composer preflight asserted in this connector-only session. Manual UAT is deferred under #2503; it does **not** excuse incomplete source or automated acceptance. No main, release/tag, cPanel or production mutation authorized.

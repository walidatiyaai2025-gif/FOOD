# V2 #2669 — P339 Security & Governance: authorized business navigation

- **Source:** `FOODEX-Unified-RBAC-ERP-Sidebar-Discussion-2026-11-09.docx`, P339, Administration; parent #2503.
- **Target:** `Foodex_V2` only. No default-branch changes, production, releases, tags or live staging/UAT.
- **Persona:** a globally authorized `security.view` administrator, including Super Admin or a properly assigned custom global security reviewer. A wholesale operational role and a store-only role cannot gain platform governance access.
- **User goal:** discover and inspect the real security posture, sessions and audit without a dead Sidebar item or independent cosmetic permission.
- **Context and data:** platform-wide Security Center, existing persisted governance/session/audit records; no data transformations, writes, migrations, or new feature flags.

## Canonical route and placement

**Reuse → Extend (existing Administration Hub)** was selected instead of adding a second security dashboard/Sidebar root. Existing `admin.administration.index` displays an Administration utility group titled from `security_governance.dashboard.title` and links to:

| Child | Canonical route | Gate |
| --- | --- | --- |
| Governance snapshot | `admin.security.index?section=governance` | `security.view` |
| Sessions | `admin.security.index?section=sessions` | `security.view` |
| Audit | `admin.security.index?section=audit` | `security.view` |

All destinations reuse existing `SecurityController::index()` with `Gate::authorize('security.view')`. Their Administration Hub parent now derives `canSecurity` from `Gate::forUser($user)->allows('security.view')` rather than a separately approximated Role/Permission expression. The whole navigation group is absent when the Gate denies; visibility is only a projection, never an independent grant. No direct route is made available to an unauthorized actor. This remains **Administration Hub → domain utility group → existing Security Center tabs**, not a new top-level Sidebar node. Existing Users & Permissions and existing Audit shortcut remain intact.

## UI/UX and localization

- Domain Administration; entity security posture, active admin sessions and audit log; workflow `view`, medium/high information density; navigation type `DOMAIN_HUB` with existing Security Center tabs as children.
- Current shared Dashboard shell, global header, administration switcher, two-column `utility-grid` and green action variants are reused. Existing responsive CSS stacks utility cards and wraps action links on narrow browsers; no new styles or JS.
- Existing Arabic/English translation keys from `security_governance` and `admin.security.tabs`, existing `dir=rtl|ltr`, native `nav` and descriptive `aria-label` maintain accessibility and keyboard navigation. No new translation files or labels.
- Parent and children live within existing Admin workflow; back path from Security Center remains Administration Hub.
- No new forms/lookups/internal ID entry, financial/operational mutation or production data.

## Negative and positive regression

`backend/tests/Feature/V2SecurityGovernanceHubAuthorityTest.php` checks:
1. Super Admin renders AR/RTL and EN/LTR hub with the three explicit canonical links; authenticating to each real Security Center section succeeds.
2. Wholesale B2B Admin without `security.view` cannot discover the group and direct HTTP entry returns 403 for every child.
3. Mixed global operational + custom global security reviewer roles permit read-only governance but do not add role-management rights.
4. A store-only custom permission, even when linked to a mixed-role actor, does not grant platform governance or expose the group.

No independent Sidebar Role Visibility toggle authorizes access. All routes and children remain protected by the same Gate used by the backend controller.

## Cross-app impact matrix

| Surface | Classification | Evidence |
| --- | --- | --- |
| Management Dashboard desktop/tablet/mobile browser | **Affected:** discoverability of existing admin security sections, only when Gate permits | AR/EN Laravel HTTP, route/role/store negative tests; existing responsive and focusable utility action component |
| Flutter Customer | **Not affected** | No Customer API, authentication, session payload or mobile route changes |
| Flutter Driver | **Not affected** | No Driver assignment/dispatch/API or mobile auth contract changes |
| Flutter Van | **Not affected** | No Van B2B route, stock, visit or identity API change |

No new mobile issue required because the patch does not alter mobile surface or its API contracts. B2B=VAN ONLY and B2C=DRIVER ONLY remain unchanged.

## Acceptance / gate

Exact latest-head `Repository Policy`, `FOODEX V2 Isolation Gate`, `FOODEX Required CI Gate`, localization and Laravel regression/backend tests must succeed before merge to `Foodex_V2`. This source-scope PR is not a production release or manual UAT result. Final consolidated manual staging/UAT remains deferred to V2 parent #2503.

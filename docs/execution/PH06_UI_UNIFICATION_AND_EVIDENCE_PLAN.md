# PH-06 — FOODEX Premium UI Unification & Screenshot Evidence Repair

Status: **Authoritative execution plan for PH-06**  
Owner: FOODEX UI/UX Implementation Coordinator  
Golden Visual Reference: `docs/design-reference/FOODEX_PREMIUM_DASHBOARD_REFERENCE.png`  
Existing brand authority: `docs/architecture/FOODEX_BRAND_SYSTEM.md`  
Existing dashboard contract: `docs/design-reference/PH05_PREMIUM_DASHBOARD.md`

## 1. Purpose

PH-06 converts the current functionally complete FOODEX 1.0.0 repository baseline into one visually unified Premium product across all Web administration surfaces while repairing the readability and portrait geometry of real mobile runtime screenshot evidence.

The Golden Visual Reference supplied by the product owner on 2026-09-26 is the visual authority for composition and visual language. It is a design reference only and **must never be counted as runtime evidence**.

This phase does not replace backend/API/authorization behavior. Existing real data, permissions, channel/store isolation, localization, business rules and release blockers remain authoritative.

## 2. Refreshed baseline before PH-06

Baseline inspected before creating this plan:

- default branch: `main`
- baseline main commit: `a3d3c2f32dda9dfad6ed3f41ba96d7f0bbd41b07`
- latest merged PR: #179, release identity for Issue #178
- #179 `required-ci-gate`: PASS
- Repository Policy: PASS
- Backend validation: PASS
- PostgreSQL/Redis deployment acceptance: PASS
- Customer Flutter validation: PASS
- Customer iOS no-codesign validation: PASS
- Driver Flutter validation: PASS
- Driver iOS no-codesign validation: PASS
- Release validation: PASS
- open PRs at refresh: none
- repository branches at refresh: `main` only
- open Issues at refresh: #124 and #125 only; both are external production blockers and remain outside PH-06
- committed runtime evidence baseline: 110 PNGs, 66 mobile / 44 web, 46/46 approved baseline rows covered

PH-06 must not fabricate or alter production endpoints, bundle/application IDs, signing values, deployment evidence, or the external closure conditions tracked by #124/#125.

## 3. Mandatory visual authority

### 3.1 Golden Reference

The Golden Reference defines the target visual composition and product feeling for:

- right-side desktop navigation in Arabic RTL
- mirrored directional behavior in English LTR
- top header
- global search
- user/profile area
- bilingual Arabic/English hierarchy
- KPI cards
- Orders & Revenue chart
- Orders Distribution donut
- Low Stock widget
- Recent Orders
- Quick Actions
- FOODEX Apps promotion
- card density
- radii
- shadows
- whitespace
- typography hierarchy
- icon sizing
- semantic status badges
- clean Premium visual rhythm

Implementation must target the same visual language across all Admin screens, not merely the dashboard.

### 3.2 Existing authority hierarchy

Workers must use the following precedence:

1. this PH-06 plan for execution order and PH-06 acceptance
2. `docs/architecture/FOODEX_BRAND_SYSTEM.md` for colors
3. Golden Visual Reference for composition/visual feel
4. `docs/design-reference/PH05_PREMIUM_DASHBOARD.md` for the established dashboard/data contract
5. `docs/business-rules/` for business behavior
6. `docs/architecture/` for architecture/security boundaries
7. `docs/api/openapi.yaml` for API contracts
8. existing backend authorization/store/channel scoping
9. runtime evidence requirements under `ScreenShots/`

A visual implementation must not weaken a higher-priority functional/security contract.

## 4. Typography — mandatory

PH-06 uses one shared typography system.

Arabic:
- family: **Tajawal**
- direction: RTL-first

English and numbers:
- family: **Inter**

Fallback:
- `system-ui, sans-serif`

Allowed weights:
- 400
- 500
- 700

Rules:
- pages must not select unrelated font stacks independently
- Arabic headings use Tajawal with strong but restrained scale
- English subtitle/helper copy is smaller and visually quieter than the Arabic primary label where bilingual pairing is shown
- KPI values and important numeric data are bold and clearly readable
- line-height, letter spacing and type scale come from shared tokens
- inputs, tables, buttons, badges, pagination and modals inherit the shared typography contract

No PH-06 page is complete while hardcoded divergent fonts remain on that surface.

## 5. FOODEX brand tokens — mandatory

Do not introduce a second palette.

- Primary Green: `#158A3A`
- Dark Green: `#165D2D`
- Bright Green: `#27B658`
- Green Soft: `#EAF7EF`
- Primary Orange: `#EE731C`
- Bright Orange: `#FC8F33`
- Orange Soft: `#FFF1E6`
- Info: `#4B8CF5`
- Error: `#EF5350`
- Ink: `#172033`
- Muted: `#667085`
- Background: `#F7F9FC`
- Border: `#E6EAF0`
- Surface: `#FFFFFF`

Shared design tokens must also centralize:
- font families
- font weights
- type scale
- spacing scale
- control heights
- icon sizes
- card/control radii
- border widths
- shadows
- focus rings
- table density
- responsive breakpoints

## 6. Shared component system

PH-06 must reduce CSS/markup drift by extracting or normalizing reusable patterns for:

- App Shell
- Header
- Sidebar
- PageHeader
- SearchBar
- KpiCard
- ChartCard
- DataCard
- Table
- StatusBadge
- ActionButton
- QuickAction
- FilterBar
- Tabs
- Pagination
- Modal
- Form controls
- EmptyState
- LoadingState
- ErrorState
- PermissionDenied state
- validation state
- confirmation dialog
- toast / flash state

Existing screens may keep their server-rendered Blade architecture; extraction does not require a framework rewrite. Shared Blade partials/tokens/classes are preferred over copied page-local CSS.

## 7. A — Premium Dashboard exact visual implementation

The B2C Premium Dashboard remains the golden implementation surface and must be refined against the Golden Reference.

### Header
Required:
- FOODEX brand association
- wide global search
- language selector
- notifications
- current user/profile
- clear desktop density matching the reference

### Right Sidebar
Required logical items, subject to actual authorized modules:
- Dashboard
- Orders
- Products
- Customers
- Delivery
- Notifications
- Mobile Apps
- Reports
- Settings
- any other already-approved modules grouped logically in multi-level navigation

Rules:
- Premium green active state
- consistent icon system and sizing
- Arabic title + quieter English subtitle where used by the reference language hierarchy
- preserve authorization: a user must not see actions/routes they are not allowed to access

### KPI row
Required:
- Active Users
- Total Orders
- Total Revenue
- Products Sold
- delta/change indicator
- soft icon backgrounds
- equal visual weight
- real backend values or deterministic approved seed data; never hardcode the screenshot numbers into production rendering

### Charts
Required:
- Orders & Revenue combination chart
- Orders Distribution donut
- canonical green/orange/status semantics
- responsive readable labels

### Bottom row
Required:
- Low Stock Products
- Recent Orders
- Quick Actions
- FOODEX Apps promotion/download card

All existing permission, store scope and real-data behavior must remain intact.

## 8. B — Global Web UI unification

After Foundation and Golden Dashboard are stable, apply the same design system across all Admin Web.

### B2B
At minimum:
- dashboard
- stores / branches
- B2B customers
- products
- inventory representation where present
- orders
- drivers / delivery
- pricing / approvals
- reports
- settings
- permissions/security
- notifications
- mobile settings

### B2C
At minimum:
- dashboard
- products
- inventory
- orders
- customers
- promotions
- delivery
- storefront preview
- content / banners
- reports
- store settings
- notifications
- mobile apps/settings
- permissions/security

### Cross-cutting UI
Also normalize:
- login
- profile
- forms
- filters
- search bars
- tables
- pagination
- tabs
- dropdowns
- modals
- confirmation dialogs
- alerts
- toasts
- loading states
- empty states
- error states
- permission denied
- validation states

No major Admin route may appear to originate from a different template family.

## 9. C — Responsive behavior

Required breakpoints retain the intent already established in PH-05:

- >=1280: premium desktop multi-column composition
- 1024–1279: reduced desktop/tablet composition; KPI 2×2 where required
- 768–1023: collapsible/drawer navigation and stacked major charts/cards
- <768: single-column content where appropriate, readable tables/cards, touch targets >=44px

RTL and LTR must mirror directional layout without reversing semantic hierarchy.

## 10. D — Mobile screenshot evidence repair

This is a real P1 quality defect.

Relevant current implementation includes:
- `apps/customer_app/test/screenshot_evidence_test.dart`
- `apps/driver_app/test/screenshot_evidence_test.dart`
- `scripts/update_screenshot_manifest.py`
- `.github/workflows/screenshot-evidence.yml`
- screenshot documentation and manifest under `ScreenShots/`

### Mandatory mobile capture geometry

For every evidence row with `surface == mobile`:

- `width < height`
- `height / width >= 1.6`
- full-resolution PNG
- the app screen occupies the natural mobile canvas
- no square crop
- no square resize
- no tiny phone embedded inside a square canvas
- no stretch/distortion
- no thumbnail used as primary evidence

Recommended target:
- 430×932 logical/equivalent portrait viewport

Equivalent real device logical viewports are allowed if the ratio/readability contract is met.

Customer and Driver evidence must both be regenerated after repair.

## 11. E — Screenshot CI guard

The evidence audit must fail CI on invalid mobile geometry.

Mandatory validation:
- valid PNG decode
- non-zero file
- minimum useful dimensions
- for mobile: width < height
- for mobile: height / width >= 1.6
- locale is valid: ar/en
- route/function is present
- state is present
- screenshot manifest is synchronized
- duplicate SHA across unrelated screens is rejected unless explicitly documented as intentional
- canonical brand verification remains active

Any failed validation makes screenshot evidence CI red.

## 12. F — Runtime-only evidence

Accepted evidence:
- screenshots generated by the actual running FOODEX Web/Flutter code under deterministic safe data

Rejected evidence:
- mockups
- generated images
- Figma exports
- Golden Reference
- manually reconstructed screenshots
- thumbnails or documentation previews

The Golden Reference is used for design comparison only.

## 13. G — Visual QA contract

Passing automated tests is necessary but not sufficient.

Every PH-06 UI Issue must:
1. run the actual affected screen
2. capture runtime screenshot evidence
3. compare visually against the Golden Reference and shared design system
4. review:
   - typography
   - spacing
   - colors
   - hierarchy
   - sidebar
   - header
   - card system
   - table system
   - RTL
   - LTR
   - responsive behavior
5. fix obvious mismatch before merge

Visual QA evidence must be recorded in the Issue/PR, screenshot audit, or an issue-specific QA document.

## 14. H — Regression protection

PH-06 tests must prevent reintroduction of:
- divergent palette values
- divergent font stacks
- dark/template-specific legacy navigation
- inconsistent radii/shadows
- non-semantic status colors
- unreadable/square mobile screenshots
- missing AR/EN directionality
- screenshot manifest drift
- unscoped dashboard/backend data

Foundation and final-audit Issues should add assertions around shared token presence and legacy-style neutralization.

## 15. Atomic implementation queue and mandatory dependencies

The execution order is strict.

1. **Global Typography + Design Tokens + Shared Shell Foundation**
   - dependency: this plan
2. **Premium Dashboard Golden Reference Implementation**
   - dependency: Foundation merged
3. **Mobile Screenshot Portrait / Readability Repair**
   - dependency: Dashboard merged only for sequencing; may touch Flutter evidence code, not shared Web foundation files
4. **Screenshot Evidence Validation Guards**
   - dependency: Screenshot Repair merged
5. **B2B Admin Web Visual Unification**
   - dependency: Foundation + Dashboard merged
6. **B2C Admin Web Visual Unification**
   - dependency: Foundation + Dashboard merged
7. **Tables / Forms / Modals / Empty/Error/Loading State Normalization**
   - dependency: B2B + B2C rollout merged
8. **Responsive Web / Tablet / Smaller Desktop QA**
   - dependency: shared-state cleanup merged
9. **Final Visual Regression + Screenshot Regeneration + Evidence Audit**
   - dependency: all preceding PH-06 Issues merged

Additional atomic Issues may be created only for already-approved scope discovered during implementation.

## 16. Worker coordination

Mandatory rule:

**One Task = One Owner = One Branch**

Lease:
- duration: 2 hours
- meaningful heartbeat only:
  - commit
  - PR update
  - progress comment
  - test result
  - QA evidence

If a lease expires for two hours without meaningful heartbeat, TAKEOVER is allowed only after reviewing:
- Issue
- acceptance criteria
- comments
- commits
- PR
- changed files
- tests
- blockers

Never open a parallel branch merely to repair an existing failing PR.

## 17. Git workflow

For every implementation Issue:

claim  
→ create/use the Issue branch  
→ implement Scope only  
→ run affected tests  
→ capture visual QA evidence  
→ PR to `main`  
→ wait for required checks / `required-ci-gate`  
→ fix failures on the same branch  
→ resolve review threads  
→ final QA  
→ Squash Merge  
→ delete branch  
→ close Issue  
→ refresh scheduler  
→ promote/claim next dependency-unblocked Issue

Direct push to `main` is prohibited.

If `main` or the required gate becomes red, stop new feature scheduling and restore green first.

## 18. Issue template

Every PH-06 implementation Issue must contain:

- Objective
- Scope
- Out of Scope
- Dependencies
- Acceptance Criteria
- API impact
- DB impact
- Permissions impact
- Localization
- RTL/LTR
- Tests
- Visual QA
- Design Reference
- Files/Modules likely affected
- Branch name
- Owner
- Lease Start
- Lease Expiry
- Definition of Done

Backlog Issues use Owner=Unassigned and Lease=None until promoted/claimed.

## 19. PH-06 Definition of Done

PH-06 is complete only when all are true:

- Dashboard achieves the same premium visual feeling/composition as the Golden Reference
- typography is unified across Web
- Tajawal/Inter shared typography tokens are active
- Sidebar/Header are unified
- B2B and B2C use the same design system
- conflicting old UI styles are removed or neutralized
- all major Admin screens look like one product
- Mobile screenshots are clear portrait evidence
- all Customer screenshots regenerated
- all Driver screenshots regenerated
- screenshot dimension guard exists and fails CI on invalid mobile aspect ratio
- Arabic RTL passes
- English LTR passes
- required-ci-gate passes on final PH-06 PR
- final real runtime screenshots are committed
- final visual audit is written
- no unresolved P0/P1 visual inconsistency remains inside approved PH-06 scope

## 20. Scheduler rule after every merge

The coordinator must:
- refresh open Issues
- refresh open PRs/branches/CI
- release dependencies
- promote the first eligible PH-06 Issue to Ready
- claim a two-hour lease
- continue without requiring the product owner to manually move backlog items

PH-06 is a completion wave, not a planning-only exercise.

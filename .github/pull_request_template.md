## Track / release isolation
Track: PLATFORM / HOTFIX / AI-V1 / AI-BUG / AI-GOV
Production release blocker: Yes / No
Target branch rationale:

## Issue
Closes #

## Summary

## Screenshots
N/A unless UI changed.

## UI placement & responsive evidence
N/A only when no business-facing UI changed.

Placement contract: `docs/design-reference/UI_PLACEMENT_SCREEN_PATTERN_CONTRACT.md`
- Placement decision: Reuse / Extend / Compose / New Screen
- Domain / entity / role / user goal:
- Canonical existing parent / route:
- Placement type:
- Primary screen pattern:
- Navigation impact / business-domain owner:
- Navigation discoverability:
  - Domain / role / capability / canonical route:
  - Navigation owner / type (`SIDEBAR|DOMAIN_HUB|PAGE_TAB|CONTEXT_ACTION|ADMIN_SETTINGS|WORKFLOW_STEP|EXPLICIT_EXCEPTION`):
  - Entry point / parent surface:
  - Permission / active context:
  - User path (start → click → landing):
  - Fallback / back path:
  - Mobile / desktop behavior:
  - AR/RTL / EN/LTR behavior:
- New-screen justification (required for New Screen; explain why Reuse/Extend/Compose are insufficient):
- Desktop evidence:
- Tablet evidence:
- Mobile evidence:
- Arabic / RTL evidence:
- English / LTR evidence:
- Permissions / entitlement evidence:
- Loading / empty / error / disabled / stale-offline states:

> The Issue title is intent, not route authority. Do not create a route or Sidebar entry merely because the Issue wording says “page” or “screen”.

## API changes
None / describe.

## DB migration changes
None / describe.

## Security impact
Describe authorization, store scope and audit impact.

## First-Run Green evidence
- [ ] `bash ./scripts/worker-start.sh` reviewed before implementation/resume
- [ ] `bash ./scripts/worker-preflight.sh --fast` passes on the final pre-push working tree
- [ ] No repeated known failure fingerprint was pushed without local reproduction/new evidence
- [ ] Changed-area selection matches the actual diff
- [ ] No unrelated dependency/lockfile drift
- [ ] Final artifacts/checks will be tied to the exact PR head SHA

## Test evidence
- [ ] backend tests
- [ ] Flutter tests
- [ ] lint/static analysis
- [ ] migration tests
- [ ] API contract validation
- [ ] security checks

## Known limitations

## Rollback notes

## Worker lease / handoff
Owner:
Branch:
Lease last renewed:
Latest head SHA:
Takeover from previous worker: No / Yes — link/comment:
Current CI state:
Next action / blocker:

> Follow the repository-wide worker policy in `AGENTS.md`. If this PR already exists, continue it in place; do not create a replacement PR for the same Issue.


## Machine-readable worker state
<!-- Keep this block current enough for Worker Watchdog takeover. -->
<!-- foodex-worker-state:v1 -->
STATE: WORKING
OWNER:
BRANCH:
PR:
HEAD:
HEARTBEAT:
BLOCKER: none
NEXT_ACTION:

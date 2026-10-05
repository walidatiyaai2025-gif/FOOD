## Track / release isolation
Track: PLATFORM / HOTFIX / AI-V1 / AI-BUG / AI-GOV
Production release blocker: Yes / No
Target branch rationale:

## Issue
Closes #

## Summary

## Screenshots
N/A unless UI changed.

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

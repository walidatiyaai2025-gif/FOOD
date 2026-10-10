# FOODEX Repository-Level Handoff

This file is a **repository-level orientation/template**, not the live task lease.

For any active task, the authoritative handoff is the target GitHub Issue/PR plus exact branch head and CI, as required by `AGENTS.md`.

## Last merged orientation
- Read `AGENTS.md`.
- Reconstruct current state from live GitHub.
- Read `.ai/PROJECT.md`, `ARCHITECTURE.md`, `CONVENTIONS.md`, then the relevant skill.
- Treat `.ai/CURRENT_STATE.md` as a dated snapshot only.

## Per-task handoff template
Use this in the Issue/PR, not only in this file:

```text
Issue:
Branch:
PR:
Owner:
HEAD:
Heartbeat:
Status: ACTIVE | BLOCKED | CI_FIX | HANDOFF_READY
Completed:
Remaining:
Tests:
CI:
Exact failure:
Next action:
External blocker:
```

Suggested machine-readable block:

```yaml
foodex-worker-state:v1
issue: <number>
branch: <branch>
pr: <number|null>
head: <sha>
heartbeat: <ISO-8601>
state: <ACTIVE|BLOCKED|CI_FIX|HANDOFF_READY>
next: <one concrete action>
```

## Durable handoff rule
Promote reusable knowledge instead of growing this handoff forever:
- architecture -> `ARCHITECTURE.md`
- decision -> `DECISIONS.md`
- convention -> `CONVENTIONS.md`
- recurring risk -> `KNOWN_ISSUES.md`
- repeatable method -> `skills/`

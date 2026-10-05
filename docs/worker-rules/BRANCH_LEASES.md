# Owner-Mission Branch Leases

For the repository owner mission command `FOOD #936 AUTO-HANDOFF`, ownership is evidence-based.

A lane is actively owned only while GitHub shows fresh execution evidence on the exact lane: branch-head movement, a machine-readable `HEARTBEAT`, or queued/running CI on the exact current head.

- Fresh qualifying evidence newer than 10 minutes: `ACTIVE_PEER`.
- Exact-head queued/running CI: `WAITING_CI`; do not collide with it.
- No qualifying evidence for 10 minutes: `TAKEOVER` on the same Issue/branch/PR.
- Red current-head CI: `CI_FIX` immediately; no timeout applies.
- Green merge-ready work with no valid active owner: `MERGE_READY`.
- Dependency-only blockers: `BLOCKED_DEP`.
- Genuine external/human blockers: `HUMAN_GATE`.

Ordinary comments, labels, PR metadata timestamps, old owner names, and status chatter never renew the owner-mission lease. The older passive two-hour convention does not apply to this owner mission.

# Takeover Rules

For `FOOD #936 AUTO-HANDOFF`, continue the existing Issue, branch, PR and current head whenever a lane becomes takeover-eligible. Never create a replacement branch or duplicate implementation merely because the previous worker disappeared.

Immediate takeover conditions are red current-head CI, merge conflict, explicit handoff, or another repository-local failure. A lane also becomes takeover-eligible after 10 minutes without qualifying execution evidence. Qualifying evidence is limited to branch-head movement, a machine-readable `HEARTBEAT`, or queued/running CI on the exact current head.

Every incomplete managed lane must resolve deterministically to exactly one queue state: `READY`, `ACTIVE_PEER`, `WAITING_CI`, `TAKEOVER`, `CI_FIX`, `MERGE_READY`, `BLOCKED_DEP`, or `HUMAN_GATE`.

`NO WORK CURRENTLY AVAILABLE` is valid only when no lane is `READY`, `TAKEOVER`, `CI_FIX`, or `MERGE_READY`. Comments, labels, PR timestamps, old worker names, and unverified claims do not prove active ownership.

Before pushing after takeover, race-check the remote branch head again. If it moved with fresh qualifying evidence, stop and reclassify instead of colliding.

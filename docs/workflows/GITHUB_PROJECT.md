# GitHub Work Board

Required workflow states:
- Backlog
- Ready
- In Progress
- Code Review
- QA
- Blocked
- Done

Repository labels mirror these as `status:*` values so automation and workers can operate even when Project UI access is unavailable.

A worker moves an issue to In Progress only after taking ownership, recording the branch and posting the two-hour lease timestamps.

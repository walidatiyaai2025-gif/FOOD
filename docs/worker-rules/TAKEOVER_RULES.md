# Takeover Rules

Takeover is allowed only when the lease expired, no valid heartbeat occurred and the task is incomplete.

Before takeover, the new worker reads issue objective/acceptance criteria, commits, PR, changed files, previous notes, tests and known problems.

Required comment:

```text
TAKEOVER
Previous owner: Worker-A
New owner: Worker-B
Issue: #123
Branch: feat/123-products
Reason: lease expired
Current commit: <sha>
```

Continue the same branch unless technically unusable. After takeover, the previous worker must stop pushing.

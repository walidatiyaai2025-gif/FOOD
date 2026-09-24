# Two-hour Branch Leases

A lease starts when the worker:
- owns/assigns the issue;
- marks it In Progress;
- records branch name;
- posts lease start and expiry.

Lease duration: exactly two hours.

Meaningful heartbeat renews the lease another two hours:
- commit pushed;
- PR updated;
- issue progress comment;
- test results posted.

Cosmetic/no-op activity is not a heartbeat. Timestamps include timezone. Workers re-check ownership before every push.

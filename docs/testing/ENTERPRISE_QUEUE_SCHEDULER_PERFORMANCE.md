# Enterprise Queue & Scheduler Performance Evidence

Issue: #1510  
Parent: #1488

The Enterprise cPanel profile reuses the existing FOODEX `SchedulerRuntime`; it does not add a
second cron system, Supervisor/systemd dependency, or persistent language daemon.

The benchmark evidence records:
- pending queue count;
- failed queue count;
- oldest pending age in seconds;
- last successful execution;
- last error summary;
- measured job/scheduler slice runtime and peak PHP memory.

The default cPanel slice gate is **<=45 seconds**. Jobs that cannot remain below that budget must be
split into idempotent, resumable chunks rather than extending the request or relying on a persistent
worker.

The feature coverage deliberately exercises the real heartbeat contract:
1. a heartbeat timestamp older than the one-minute window represents a missed run;
2. the next heartbeat invokes the existing campaign dispatcher and Laravel `schedule:run`;
3. a second invocation inside 55 seconds is suppressed;
4. disabling the heartbeat performs no scheduler work.

Backlog collection is intentionally transport-neutral so Redis/database/module-health adapters can
supply timestamps/counts without coupling the certification format to one queue driver.

# Enterprise cPanel Deployment Smoke

Issue: #1513  
Parent: #1488

This certification lane reuses the existing FOODEX deployment acceptance environment and the
existing `SchedulerRuntime`. It does not introduce a second scheduler, Supervisor/systemd
dependency, or persistent Python/Node/Java process.

The smoke executes on the disposable MySQL/Redis acceptance profile and requires all of the
following to succeed:

- `php artisan config:cache`;
- `php artisan route:cache`;
- `php artisan view:cache`;
- `php artisan schedule:list`;
- `php artisan migrate:status`;
- the canonical scheduled-notification command remains registered;
- `SchedulerRuntime` still owns `# FOODEX_MANAGED_SCHEDULER` and invokes Laravel
  `schedule:run`;
- the baseline scheduler path contains no Supervisor/systemd requirement.

The test emits a machine-readable log marker:

`FOODEX_CPANEL_SMOKE_JSON=<json>`

This is a deployment smoke certificate, not the latency certificate. Absolute cPanel p95
guardrails still require the dedicated cPanel reference performance run.

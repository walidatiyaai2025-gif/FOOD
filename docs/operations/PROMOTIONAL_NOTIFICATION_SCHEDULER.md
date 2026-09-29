# FOODEX Promotional Notification Scheduler

FOODEX promotional campaigns are dispatched by Laravel Scheduler and use the same notification dispatcher as Send Now.

## Self-managed production execution

Manual cron setup is not a release requirement.

FOODEX provides two execution layers:

1. **Managed cron (primary)** — first install and every system update automatically attempt to install/update one FOODEX-managed crontab entry that runs `php artisan schedule:run` every minute.
2. **Application scheduler heartbeat (fallback)** — every installed FOODEX web/API runtime has a terminable, file-locked heartbeat. At most once per minute it runs `php artisan schedule:run` when hosting policy prevents crontab provisioning.

The heartbeat runs the entire Laravel schedule, not only notification campaigns. Therefore future scheduling features inherit the same execution contract.

The managed cron entry is idempotent and identified by `# FOODEX_MANAGED_SCHEDULER`; installer/update runs replace the FOODEX-managed entry rather than creating duplicates.

## Campaign command

The scheduled command is:

```bash
php artisan foodex:dispatch-scheduled-notifications
```

It can still be executed manually for controlled diagnostics. Normal operation is owned by FOODEX through `schedule:run`.

## Execution semantics

- Admin schedule input is interpreted in `Asia/Kuwait`.
- Persisted execution timestamps are normalized by the application.
- One-time campaigns complete after one successful occurrence.
- Recurring campaigns create a new Notification record for each occurrence.
- Recurrence supports minutes, hours, days, weeks, and months.
- Optional end time and maximum run count can terminate a recurring campaign.
- Paused campaigns are not dispatched.
- Cancelled and completed campaigns cannot be sent again.
- Failed scheduler execution is recorded and retried after five minutes.
- Each successful occurrence has campaign run history, audit evidence, and normal per-device push delivery logs.

## Isolation

- B2B Admin manages only B2B campaigns and authorized wholesale stores.
- B2C Store Admin manages only B2C campaigns for assigned stores.
- Super Admin can manage both channels.
- The legacy manual notification center remains restricted to Super Admin.

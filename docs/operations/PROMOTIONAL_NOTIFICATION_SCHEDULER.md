# FOODEX Promotional Notification Scheduler

FOODEX promotional campaigns are dispatched by the Laravel scheduler.

## Production requirement

Run Laravel's scheduler once every minute from the operating system scheduler. The application itself decides which one-time or recurring campaigns are due.

Example cron entry when PHP and the FOODEX backend are available at their normal production paths:

```cron
* * * * * cd /path/to/FOODEX/backend && php artisan schedule:run >> /dev/null 2>&1
```

Do not create one cron entry per campaign. The single Laravel scheduler entry handles all campaigns.

## Campaign command

The scheduled command is:

```bash
php artisan foodex:dispatch-scheduled-notifications
```

It can be executed manually for controlled operational verification. Normal production operation should use `php artisan schedule:run`.

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

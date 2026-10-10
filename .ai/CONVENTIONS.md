# FOODEX Engineering Conventions

Read `AGENTS.md` first. These conventions help an agent choose the expected implementation shape quickly.

## Branch / task
- Use the existing Issue-defined branch when one exists.
- New work: create/claim an atomic Issue before a branch.
- Typical prefixes: `feat/`, `fix/`, `chore/`, `test/`, `release/`.
- Never create retry/final/fixed-again branches for the same task.
- Keep scope atomic and ownership explicit.

## Laravel / PHP

Backend root: `backend/`.

Useful commands from `backend/`:
```bash
composer test
composer lint
composer analyse
php artisan test
vendor/bin/pint --test app routes tests
```

- Validate requests at the HTTP boundary.
- Authorize through policies/gates/middleware and server-side scope checks.
- Keep controllers thin when business transitions belong in Actions/Domain/Services.
- Add regression tests for fixed bugs.
- Use transactions for multi-write business transitions.
- Eager-load intentionally; avoid hidden N+1 behavior.
- Do not expose internal exception/stack details to users.
- Follow Pint output exactly.

## API
- Version under `/api/v1`.
- Keep OpenAPI synchronized with contract changes.
- Use stable machine-readable error shapes consistent with nearby endpoints.
- Validate ownership/store/channel scope on every record operation.
- Design retried state-changing requests to avoid duplicate transitions/ledger rows/notifications.

## Database
- Migrations must be safe for existing production data.
- Avoid destructive schema changes without an explicit migration/backfill/release plan.
- Add justified indexes for new high-cardinality filters/joins.
- Put defaults/nullability in the schema deliberately.
- Test migration-sensitive features with realistic existing data.
- Preserve audit/ledger history unless an explicit safe requirement says otherwise.

## Dashboard UI

Before changing Dashboard UI read:
- `docs/design-reference/DASHBOARD_UI_UX_CONTRACT.md`
- `docs/quality/LOCALIZATION_CONTRACT.md`

Expected patterns: domain sidebar + function tabs, clear FOODEX create actions, Modal/Drawer/Wizard where appropriate, exact-record actions, compact ellipsis row menu, business labels rather than raw DB IDs, authoritative lookups, loading/empty/error/stale states, AR/EN, RTL/LTR and responsive behavior.

## Flutter apps
- identify role/app boundary;
- use secure storage for tokens/secrets;
- never persist plaintext passwords for biometric unlock;
- model loading/empty/error/offline states;
- treat stale push/deep-link payloads safely;
- authorize exact-record navigation;
- test parsing and critical flows.

Shared API change => evaluate Customer, Driver and Van consumers.

## Localization
- No new user-facing hard-coded language literals where translation keys are expected.
- AR and EN catalogs move together.
- Localize business enums/statuses before display.
- Parser false positive => fix gate + regression test, not broad allow-marker accumulation.

## Testing evidence

A good PR states behavior reproduced, change made, exact commands/tests, head SHA, relevant CI and runtime/visual evidence when required.

## Documentation updates

Update `.ai/` only when knowledge is durable. Do not churn it for ordinary implementation detail.

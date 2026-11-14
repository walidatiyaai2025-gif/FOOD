# V2 #3508 — Update ZIP case-fold collision and Windows path-component alias guard

## Source, owner and isolation

- Source: P112, G005 #2746, #2846; master #2503.
- Issue: #3508; integration branch: `Foodex_V2` **only**; no main, tag, signed release, production update or deployment.
- Issue-specific branch: `fix/3508-v2-update-zip-case-alias-guard`.
- Limited files: canonical `LaravelUpdateRuntime` preflight, new focused regression and this issue QA.
- Related pre-existing changes: merged #3499 protects live dotenv variants; merged #3402 protects migration-detection path canonicalization. This leaf addresses a distinct target-collision and trailing-component problem.

## Preflight defect and fix

The ZIP archive entry scanner previously recorded exact byte-string paths in `$seen`, so two canonical ZIP members differing only by ASCII case could target the same file on case-insensitive hosts. It now tracks case-folded paths as well, rejecting the second alias before any backup, maintenance or extraction. Existing exact-duplicate and noncanonical path decisions remain in their original order.

The path guard now rejects empty components and names ending in a period or space before any protected-runtime checks. On Windows-like filesystems, `vendor.`, `Storage. ` or other trailing-dot/space names can alias live runtime directories or files. Do not treat such names as safe release entries, even if their ZIP member spellings are distinct.

The checks are intentionally conservative for cross-host portability. They do not claim a demonstrated live exploit and do not replace signed publisher authenticity, SHA-256 verification, rollback integrity or full supported upgrade acceptance.

## Test contract

`backend/tests/Feature/V2UpdateZipCaseAliasGuardTest.php` creates real temporary `ZipArchive` packages and calls `LaravelUpdateRuntime::preflight()` (no install or user-data mutation):

- Negative: same-file names differing in case; case-only alias in a parent directory; config directory casing.
- Negative: trailing-dot aliases for `vendor` and `.git`, trailing-space component aliases and trailing-dot file suffix.
- Positive: canonical distinct source paths and `.env.example` / `.env.production.example`.

The test uses the actual package manifest hash and validates error contracts without relaxing the existing ZIP entry/budget or protected-path checks. Acceptance requires PHP syntax, Pint, targeted Laravel regression, full backend/security and AR/EN gates, real MySQL/Redis deployment CI, Repository Policy, V2 Isolation Gate and terminal Required CI on the exact final PR head. Any pending/failed gate means **do not merge or close**.

## Scope and app impact

| Surface | Impact |
| --- | --- |
| Backend | ZIP preflight only; no routes, migrations, permissions, schema, translations or public APIs |
| Dashboard | Existing Super Admin System Updates verification/install action and error contract unchanged |
| Customer | No source/API/Flutter change; Customer channel behavior unchanged |
| Driver | No source/API/Flutter change; B2C DRIVER ONLY preserved |
| Van | No source/API/Flutter change; B2B VAN ONLY preserved |

Parent #2746/#2846/#2503 remain open for complete signed package, install, upgrade, backup and recovery validation. Final manual/staging UAT deferred on parent gate only after atomic source acceptance is verified.

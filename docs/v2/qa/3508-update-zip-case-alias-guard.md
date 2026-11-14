# V2 #3508 — Windows ZIP trailing-component alias guard (post-#3507 reconciliation)

## Source, owner and isolation

- Source: P112, G005 #2746, #2846; master #2503.
- Issue: #3508; integration branch: `Foodex_V2` **only**; no main, tag, signed release, production update or deployment.
- Issue-specific branch: `fix/3508-v2-update-zip-case-alias-guard`.
- Limited files: canonical `LaravelUpdateRuntime` preflight, new focused regression and this issue QA.
- Related pre-existing changes: merged #3499 protects live dotenv variants; merged #3402 protects migration-detection path canonicalization. Canonical ZIP case-fold duplicate and file/ancestor target protection is already integrated by #3507 / PR #3513. This leaf adds only trailing-dot/space component protection and regression coverage to the canonical runtime.

## Preflight defect and fix

Case-insensitive file-target and file/parent namespace collision rejection was implemented and verified in merged #3507 / PR #3513; this issue neither reimplements nor changes that canonical guard. Its existing regression also exercises those already-integrated safety contracts.

The preserved original #3508 path guard rejects empty components and names ending in a period or space before any protected-runtime checks. On Windows-like filesystems, `vendor.`, `Storage. ` or other trailing-dot/space names can alias live runtime directories or files. Do not treat such names as safe release entries, even if their ZIP member spellings are distinct.

The checks are intentionally conservative for cross-host portability. They do not claim a demonstrated live exploit and do not replace signed publisher authenticity, SHA-256 verification, rollback integrity or full supported upgrade acceptance.

The original issue branch history is preserved with a non-force two-parent merge from Foodex_V2, with only the three issue-fenced paths differing from its current integrated tree. The complete canonical casefold and ancestor-namespace checks from #3507 remain unchanged.

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

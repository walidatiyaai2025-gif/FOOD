# V2 G006 / #2747 — exact Update ZIP historical compatibility integrity

**Source:** Unified RBAC / ERP / Sidebar P112, G006. **Parent:** #2503. **Merge base:** `Foodex_V2` only. The source is the archive/manifest contract of `scripts/build-update-bundle.py`, not an invented release build.

## Delivery and limitation

`scripts/v2_upgrade_package_provenance.py` verifies the *actual bytes supplied* for both historical edges: v1.0.124 → v1.0.125 and v1.0.125 → v1.0.127. It requires one `FOODEX-Update.zip`, its `FOODEX-Update.json`, `FOODEX-Update.sha256.txt` and exact `FOODEX-Update.files.txt` per edge. The two edge sources have separate immutable SHA identities.

The verifier rejects a missing archive, mismatched SHA-256, invalid or non-exact version edge, wrong `VERSION` inside ZIP, inconsistent file manifest, omitted/duplicate/foreign migration declaration, missing migration member, duplicate/aliased normalized path, archive traversal, symlink, `.env`/storage or non-runtime content, full-redeploy-required delta, inconsistent source SHA and corrupt ZIP data. It does **not** extract, apply or execute untrusted archives.

**Important:** SHA-256 establishes consistency, **not publisher authenticity**. A trusted digital signature/approved signing key and version trust must still be verified by the actual installer/updater release authority. An archive integrity PASS is **not** evidence that migrations ran successfully, that existing records or secrets were preserved, or that recovery worked. Never mark this Issue complete on this test alone.

## Migration manifest completeness regression

The ZIP's `backend/database/migrations/` members and manifest `migrations` list must have **exactly the same unique paths**, not merely a valid subset. A listing omitting one ZIP migration or repeating another can otherwise pass checksum integrity when the metadata was changed alongside the archive. The negative test exercises a real second ZIP migration, an omitted declaration and a duplicated declaration; the complete valid list still passes. This is offline integrity verification, **not** signed-publisher provenance or a database-upgrade success claim.

## Reproduction

```bash
python3 -m unittest scripts.tests.test_v2_upgrade_package_provenance -v
python3 scripts/v2_upgrade_package_provenance.py \
  --upgrade-124-125 /path/to/v125-exact-update-bundle \
  --upgrade-125-127 /path/to/v127-exact-update-bundle \
  --source-125 <immutable-git-sha-for-125> \
  --source-127 <immutable-git-sha-for-127>
```

The issue-specific [G006 unit-test workflow](../../../.github/workflows/v2-g006-package-provenance.yml) runs these synthetic ZIP regressions against the exact PR head under read-only GitHub permissions. It is an auxiliary check: the existing Required CI Gate still controls integration and the actual MySQL/package tests below still control source acceptance.

Each path must contain all four genuine outputs from the same underlying build. The script prints `"status": "archive_integrity_only"`, not `upgrade_passed`. Tests synthesize private in-memory ZIPs; no real release assets, customer uploads, server credentials, MySQL contents or personal accounts are accessed.

## Full G006 acceptance still pending

- Obtain separately authorized original, signed historic 124→125 and 125→127 packages and inspect their immutable source/base SHA, signatures, version compatibility and actual SHA-256 manifest.
- Run *those exact ZIPs* through clean MySQL/MariaDB installations with a preserved non-empty user/order/ledger, `.env`, storage upload marker and approved migration state; prove successful upgrade and idempotent retry, including interrupted 125-recovery paths and rollback/recovery evidence.
- Run fresh installation and supported V2 upgrade against the **actual signed final isolated V2 Setup/Update ZIP** built from the frozen exact `Foodex_V2` SHA. Do not treat conditional historical workflows `release-update-bundle.yml` or `release-factory.yml` as current V2 evidence when their conditions do not trigger on V2 PRs.
- Verify `G004` #2745, `G005` #2746 and overarching P112 #2846 source/installation/update/recovery gates independently. CI must include required exact-head Repository Policy, V2 isolation, backend/security, AR/EN, real MySQL and all relevant mobile gates. A failing Enterprise Performance baseline #3160 must not be bypassed.
- Record ZIP filename, source SHA, signature verdict, archive SHA-256, test runner/source SHA, MySQL version and sanitized data-preservation and recovery outcomes. Never upload production `.env` or data.

## Cross-surface impact

| Surface | Decision |
| --- | --- |
| Backend | New offline Python ZIP/manifest integrity verifier and regression tests only, **no** Laravel/API/schema modifications |
| Dashboard | No new dashboard update UI, route or permission; Admin ZIP updater remains independently owned by #2746 |
| Customer | No Flutter/API/contract changes; remains tested separately in final synchronized release gate |
| Driver | No Flutter/API/dispatch changes; B2C → DRIVER ONLY invariant unchanged |
| Van | No Flutter/API/dispatch changes; B2B → VAN ONLY invariant unchanged |
| AR/EN/RTL | No new user-facing strings; CLI uses machine output and existing Admin updater UI remains intact |

**Status:** bounded archive-integrity verification is delivered only when its exact-head CI proves the tests executed. Full historical and V2 ZIP fresh-install/upgrade/recovery **not accepted yet**. Keep #2747 open until all above real-package acceptance is confirmed; no main, release, tag or production actions.


## Actual historical GitHub Release archive inspection — 2026-11-13

This is **real public release-archive integrity evidence**, not a fabricated synthetic ZIP nor a production/V2 release. The issue-owned workflow now downloads the original **unmodified** published assets from the immutable-looking tag references and independently pins their Git SHA and archive SHA-256. If any fixed tag or asset is changed later, the verification fails instead of accepting a silently replaced archive.

| Historical update edge | Immutable release tag → Git commit | Actual archive SHA-256 | ZIP members | Listed / declared migrations |
| --- | --- | --- | ---: | ---: |
| 1.0.124 → 1.0.125 | `v1.0.125` → `1a4d2ba341424c816040a7185a9ab1df59c3d574` | `b4fd85f983469c754c50cbb2b3d3f6d6474545863d4ea41a7b3728cce36cfb52` | 1998 | 220 |
| 1.0.125 → 1.0.127 | `v1.0.127` → `a75d3780a8cf1eeb702781adbb17a5dcfd7d8130` | `fd7c801c333c902d5d6704283eba75de24c7aeae46bcbbe6ebaac6e5dc6fdf46` | 1999 | 221 |

Source ancestry was verified with GitHub compare: `v1.0.124` → `v1.0.125` (4 commits, no behind) and `v1.0.125` → `v1.0.127` (7 commits, no behind). The release-tag SHAs agree with each corresponding manifest `source_base` and `source_commit`. The actual ~79 MB archives were downloaded with authenticated GitHub CLI read-only to an inspection workspace, and SHA-256 values matched both the published JSON and the standalone checksum text. Native ZIP inspection checked internal `VERSION`, exact ordered `FOODEX-Update.files.txt`, manifest file counts, migration counts, duplicate paths, symlink and traversal indicators. No mismatches found. The new CI job independently runs the canonical Python verifier on these **same real pinned assets**.

- Source: [GitHub release v1.0.125](https://github.com/walidatiyaai2025-gif/FOOD/releases/tag/v1.0.125) and [GitHub release v1.0.127](https://github.com/walidatiyaai2025-gif/FOOD/releases/tag/v1.0.127).
- **Strict limitation:** Release ZIP and checksum bytes alone **do not authenticate the publisher**, prove a signature trusted by the updater, prove compatible PHP/Composer runtime, run MySQL migrations, preserve `.env`/uploads/financial rows, or demonstrate interrupted-update recovery. Neither historical release advertised a separately verified trusted package signature in this inspection. The test only yields `archive_integrity_only`.
- Do not call G006 complete or merge solely on this evidence. Actual source-approved signed packages, clean installation, supported upgrade, database-preservation/backup and recovery acceptance from G004/G005/P112 must be evidenced on the exact tested packages before marking full G006 done.


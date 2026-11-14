# V2 G006 / #3359 — verify the exact legacy recovery ZIP before SQL upgrade acceptance

Parent: #2747 (G006 / source P112); master #2503. Target: `Foodex_V2` only.

## Source discrepancy and design

The existing conditional v1.0.124 → v1.0.125 MySQL recovery test used `test -s FOODEX-Update.zip` and overlaid it with `unzip -q -o` without authenticating the package's SHA-256 against the accompanying JSON, validating target/minimum versions or verifying the Dashboard representation. Meanwhile v1.0.125 → v1.0.127 already checked target/minimum/hash with a small inline Python block, creating unequal acceptance guarantees across the supported historical recovery lanes.

The change introduces one standalone pure-Python preflight `scripts/verify-recovery-update-manifest.py` and invokes it **before any extraction or worktree/database changes** from both `scripts/ci-125-recovery-upgrade.sh` and `scripts/ci-127-upgrade.sh`.

The verifier checks:
- nonempty and structurally recognized ZIP and readable valid JSON;
- schema version `1`, `available=true`, `requires_full_redeploy=false`;
- exact `package` name, target release and minimum currently installed release, with `v124 → v125` and `v125 → v127` separately pinned;
- exact SHA-256 over the ZIP bytes, constant-time hex comparison with the manifest;
- consistency of `dashboard_fields.target_version`, `minimum_current_version` and `sha256` with the manifest top-level values;
- clear nonzero exit and diagnostic on invalid pairing, before any ZIP overlay.

**This is checksum/provenance consistency, not signed authenticity.** SHA-256 alone cannot establish package trust. Signed update packages, trusted issuer keys and real Web Update Center safe-apply still belong to #2746/#2846 and must not be declared complete by this change. ZIP Slip/duplicate-path handling belongs to the existing updater path-hardening issues, including #3354; this test does not replace those controls.

## Hermetic regression evidence

New `scripts/tests/test_verify_recovery_update_manifest.py` contains 9 `unittest` cases using disposable local ZIP/JSON fixtures. They cover valid v125; mutated ZIP; wrong target/minimum/package filename; conflicting Dashboard hash/version; wrong schema/availability/deploy state/hash format; missing/corrupt JSON; missing/empty/not-a-ZIP; both real shell scripts calling preflight **before `unzip`**; and independence of v125 and v127 acceptance versions.

`scripts/validate-repo.sh` runs the above nine tests and `bash -n` for both shell entry points on each Repository Policy execution, which is independently required as part of FOODEX Required CI. This makes the changed guard observable on ordinary `Foodex_V2` pull requests, without needing a real legacy release artifact just to prove the negative preflight.

The existing conditional MySQL `ci-125-recovery-upgrade.sh` and `ci-127-upgrade.sh` **still require separately triggered exact ZIP artifact runs** to establish full G006 upgrade compatibility. Ordinary Laravel/MySQL CI is not evidence that they ran.

## Cross-surface impact

| Surface | Code change | Authorization/UX impact |
| --- | --- | --- |
| Backend | No Laravel runtime/controller/model/migration changes; offline release-validation scripts only | No API, finance, RBAC, database, entitlement or HTTP behavior change |
| Dashboard | No Blade, route, Sidebar, update UI, CSS or translation changes | AR/EN, RTL/LTR, responsive and a11y unchanged; no new navigation placement |
| Customer app | No Flutter, auth or API contract changes | Existing Customer B2B/B2C ordering unaffected |
| Driver app | No Flutter, Driver API or dispatch changes | B2C = Driver only invariant unaffected |
| Van app | No Flutter, Van API, stock or trip change | B2B = Van only invariant unaffected |

Installer remains the existing /install workflow; no production ZIP, cPanel or shared-host deployment is performed.

## Definition of done — issue #3359 versus parent G006

For **#3359 only**, require one exact issue-scoped branch/PR, all 9 negative/positive tests executed in Repository Policy, shell syntax checks, exact final-head V2 Isolation Gate and FOODEX Required CI terminal SUCCESS, and verified GitHub merge **only into `Foodex_V2`**.

Keep **#2747 OPEN** pending real signed artifact trust, exact v124→v125→v127 ZIP integration runs with MySQL, preserved data/uploads/backups and verified recovery, same final V2 source SHA and required package CI evidence. Passing this isolated ZIP preflight and a general Laravel/MySQL workflow does **not** certify those requirements.

No merge to `main`, tags, production deployment or release publication without a new explicit owner order.

# V2 G006 / #3447 — reject duplicate JSON keys in legacy recovery manifests

**Source:** P112 / G006, parent #2747; already merged #3359 / PR #3363 provides the original legacy v124→125 and v125→127 ZIP+JSON preflight. **Integration:** `Foodex_V2` only; no main, release or production.

## Reproduced gap / minimal fix

Python `json.loads` silently accepts duplicate keys and retains the last occurrence. In the legacy acceptance verifier, that lets `available`, `sha256`, `schema_version` and nested `dashboard_fields` be represented ambiguously in an otherwise checksum-consistent JSON manifest. This is a **test-package trust interpretation gap**. It is not proof of a production exploit; only a true publisher-signature check establishes authenticated provenance.

The verifier now passes a strict `object_pairs_hook` to its **existing** JSON decoder. The hook processes every object at every depth and raises `ValueError` before any ZIP digest/read, database action or archive overlay if an object contains a second copy of any key. It does not normalize, merge or silently prefer an occurrence. Existing schema, target/minimum version, release availability, Dashboard parity, ZIP structure and SHA-256 behavior are unchanged.

## Regression and reproduction

`python3 -m unittest scripts.tests.test_verify_recovery_update_manifest -v`

Real temporary ZIP+manifest tests exercise duplicate top-level `schema_version`, `available` and `sha256`, and a duplicate nested `dashboard_fields.target_version`. Each is refused with a nonzero subprocess result and no PASS marker. The original valid JSON manifest passes again after each negative scenario. The earlier suite also confirms v125/v127 release version independence and the guard running before `unzip -q -o` in both historical shell test scripts.

Check exact-final-head **Repository Policy**, **FOODEX V2 Isolation** and **FOODEX Required CI** (including real Laravel/MySQL/Redis acceptance as applicable) before merging the sole #3447 PR. This one test guard does **not** prove that historic ZIPs are publisher signed, installed safely, or recovered data; parent #2747 and G004/G005/P112 remain OPEN until real signed-package acceptance.

## Cross-surface decision

- Backend: offline CI Python preflight only; no Laravel route, permission, service, migration, tenant, finance or database mutation.
- Dashboard: no Web Installer/Update Center UI, AR/EN, RTL/LTR, role or authorization change.
- Customer: no API/Flutter or channel change.
- Driver: no B2C token, assignment or dispatch change; B2C=DRIVER ONLY remains.
- Van: no B2B endpoint/Flutter or dispatch change; B2B=VAN ONLY remains.

No secrets, customer records, release artifacts, live database, main branch, production or cPanel files are modified.
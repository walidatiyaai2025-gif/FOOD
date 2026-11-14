# V2 #3386 — Update ZIP package-path symlink guard

## Source and threat boundary
- Parent release/update requirements: #2746 (G005), #2846 (P112), master #2503.
- Isolated integration target: `Foodex_V2` only.
- Before this change, `UpdatePackageValidator::validate` accepted a local symbolic-link ZIP path when the linked target was readable and its SHA-256 matched the supplied manifest. Both `is_file` and `hash_file` follow these links. This is a service-layer file-read boundary even if the current protected admin upload normally stores a fresh regular file.
- Canonical behavior: fail closed on the package leaf **and every ancestor symlink**, before any availability or integrity read. Return a generic error without disclosing the linked target.

## Implementation and focused security evidence
- `backend/app/Domain/Updater/UpdatePackageValidator.php`: check every package path component for symbolic links before `is_file`, `is_readable` and `hash_file`. The existing SHA-256, migration-backup declaration and SemVer compatibility rules remain unchanged.
- `backend/tests/Unit/V2UpdaterPackageSymlinkGuardTest.php`: positive ordinary file with correct checksum, direct symlink with correct checksum denied, ancestor-directory symlink with correct checksum denied, and dangling symlink denied before the generic file-availability check.
- Fixtures use disposable local temporary paths; they neither inspect nor modify production .env, uploads, accounts, business records or the live release.
- The check is a defense-in-depth guard against pre-existing symbolic aliases in the trusted update input. No claim is made that it eliminates all filesystem time-of-check/time-of-use races by untrusted users with write access to the uploader's private directory.

## Surface and mobile impact matrix
- **Backend:** update-package validation hardening only. The same validation method is called by `UpdateManager`; no route or response payload contract changes.
- **Dashboard:** existing Super Admin System Updates upload uses the protected updater; error remains a generic existing validation failure. No new UI, navigation, permission, AR/EN copy, RTL or LTR changes.
- **Customer:** NOT AFFECTED; no Flutter, API, order, user identity or permission change.
- **Driver:** NOT AFFECTED; no Flutter, API, wallet or B2C dispatch change.
- **Van:** NOT AFFECTED; no Flutter, API, custody or B2B Van routing change.
- Authorization stays server-side. B2B=VAN ONLY and B2C=DRIVER ONLY are unchanged.

## CI and acceptance
Run focused PHPUnit, PHP syntax/Pint/PHPStan/security, required backend suite, locale parity and applicable real MySQL/Redis gates on the **exact final PR head**. Confirm Repository Policy, FOODEX V2 Isolation and terminal FOODEX Required CI Gate are all successful before any merge. Preserve the one Issue / one Branch / one PR contract. Close only this atomic security leaf when merge to `Foodex_V2` and final checks are verified; parents remain open for genuine signed ZIP, fresh install, upgrade and recovery certification.

No `main`, release, tag, production deployment or cPanel side effects.

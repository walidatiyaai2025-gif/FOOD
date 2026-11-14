# #3506 — V2 Updater ZIP case-insensitive target collisions

**Source:** P112 / G005; parents #2746, #2846 and V2 master #2503. **Integration target:** `Foodex_V2` only. No `main`, tags, live installer/updater, staging publication or production.

## Verified defect and narrow fix

`LaravelUpdateRuntime::archiveEntriesFrom()` already rejected exact and slash-normalized duplicate ZIP members after merged #3354. It still keyed `$seen` by the exact canonical path: an archive containing `backend/app/Foo.php` and `backend/app/foo.php` passed this duplicate check. On case-insensitive target volumes, these are the same release file even if ZIP lists them separately, so backup, activation and rollback may disagree about target contents.

Keep the original normalized-path identity check, then reject a second member whose UTF-8 `mb_strtolower` normalized target has been encountered. This preserves existing ZIP entry count/size, symlink, executable public path, ZIP-Slip, canonical naming and protected `.env*` restrictions. Reject before creating backup state or mutating live release files. An accepted ZIP remains subject to independent manifest SHA-256, publisher signature and version rules in the manager/validator; this change does **not** certify signing.

## Automated acceptance

`backend/tests/Feature/V2UpdaterCaseCollisionArchiveTest.php` constructs real temporary ZIP archives and checks:

1. `Widget.php` + `widget.php` rejected at preflight; no live sentinel change and no backup created.
2. Differences in case across ancestor segments (`Nested/Item.php` vs `nested/item.php`) rejected; Unicode uppercase/lowercase aliases rejected.
3. Direct `backupFiles()` and `extractRelease()` independently reject ambiguous archives before copying or extracting.
4. Distinct canonical release filenames still pass, and prior `.Env.Production` protection still rejects credentials.

Testing remains synthetic and does not execute production migration, live backup or publish a release. Required evidence: PHP syntax, Pint, Laravel focused/full tests, security, AR/EN, real MySQL/Redis tests as applicable, and Repository Policy + V2 Isolation + Required CI success on **exact final PR HEAD** before merge.

## Surface and mobile impact classification

- **Backend:** Update ZIP runtime archive enumeration only; no API/schema/migration/queue or route change.
- **Dashboard:** Existing Super Admin Settings → System → System Updates retains its authorized Verify/Install workflow and existing error shape; no new Blade, navigation, translation or RTL/LTR change.
- **Customer:** No public API or Flutter change, no customer/Wholesale order behavior change.
- **Driver:** No public API or Flutter change; B2C DRIVER ONLY unchanged.
- **Van:** No public API or Flutter change; B2B VAN ONLY unchanged.
- **Security/data:** Strictly fail closed on ambiguous update archives, no release or user data mutation during negative tests.

Only this atomic guard may close as `CODE_COMPLETE / FINAL_ACCEPTANCE_DEFERRED` after reviewed merge and required exact-head CI. The complete signed Setup/Update packages, trusted publisher, real data-preserving upgrade/recovery, final cross-app artifacts and manual UAT remain on #2746/#2846/#2503.

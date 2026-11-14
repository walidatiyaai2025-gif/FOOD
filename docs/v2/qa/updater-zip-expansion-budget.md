# V2 #3414 — bounded Update ZIP expansion on shared hosting

**Authoritative source:** P112 (Admin Update ZIP), #2746 (G005), #2846 (Release), parent #2503. **Integration branch:** `Foodex_V2` only; no release/tag/cPanel deployment/main changes.

## Verified failure mode

Before this patch, `LaravelUpdateRuntime::preflight()` reserved disk capacity based on three times the **compressed** Update ZIP size, and `extractRelease()` copied unbounded decompressed streams into temporary release files. A small ZIP can contain highly compressible payloads vastly larger when extracted. Exhaustion of disk space on shared hosting can interrupt staging and recovery, even if the compressed archive passed checksum/signature verification. This is an identified resource-boundary gap, **not** evidence of an actual production exploit.

## Safety contract

The centralized `archiveEntriesFrom` helper now examines `ZipArchive::statIndex` uncompressed sizes before preflight, file backup, and extraction. It fails closed if:

- an ordinary file has unreadable or negative uncompressed size metadata;
- any single member exceeds the configured expanded-file limit;
- the sum of all members exceeds the total expanded-file limit (using overflow-safe subtraction).

Defaults (bytes) in `backend/config/foodex.php`:

| Key | Environment override | Default |
| --- | --- | --- |
| `foodex.update_max_entry_uncompressed_bytes` | `FOODEX_UPDATE_MAX_ENTRY_UNCOMPRESSED_BYTES` | 268435456 (256 MiB/member) |
| `foodex.update_max_total_uncompressed_bytes` | `FOODEX_UPDATE_MAX_TOTAL_UNCOMPRESSED_BYTES` | 1073741824 (1 GiB/archive) |

Limits are always positive. Increase them only for a **verified signed** larger release after checking available disk quota, backup requirements and cPanel host capabilities. `preflight` reserves the greater of `3 × compressed ZIP` and `2 × expanded file sizes` of free backup-root disk space. It is not a physical quota guarantee when storage/backups reside on different mounted volumes; the admin must use a supported deployment/recovery plan on such hosts.

During extraction, `stream_copy_to_stream` is limited to **one byte beyond** the ZIP entry's declared uncompressed size. The staged file is activated only when exactly the expected number of bytes was extracted. A mismatch deletes the temporary file and fails closed; source ZIP streams and staged destination streams are closed. An entry is never read wholly into PHP memory.

## Regression evidence and scope

`backend/tests/Feature/V2UpdaterZipExpansionBudgetTest.php` constructs actual `ZipArchive` fixtures. It verifies both a compressed member exceeding the individual limit and an aggregate over-limit archive through **preflight**, **backupFiles** and **extractRelease** with no backup or live sentinel mutation. A small authorized ZIP still installs and rolls back correctly. Normal ZIP directory entries are skipped and do not consume expanded file bytes.

Existing protections remain authoritative: cryptographic package signature/source verification, checksums, version compatibility, Symlink/ZIP Slip/path traversal, duplicate targets, static-public-script guard, Super Admin authorization, update lock, database/file backups, migration and recovery semantics. An expansion-budget test does **not** prove these independent release gates complete.

## Runtime, UI and mobile impact matrix

| Surface | Impact / evidence |
| --- | --- |
| Backend Laravel | Bounded ZIP metadata, free-space budget and streaming write. No migrations, API routes, auth/tenant/SoD or uploads change |
| Admin Dashboard | Reuses `Settings → System → System Updates` and existing update failure display; no new navigation, rendered strings or permissions |
| Customer Flutter | No app file, public API, payload or Customer workflow changed |
| Driver Flutter | No app file, B2C dispatch, cash/collection or API changed |
| Van Flutter | No app file, B2B route, custody, inventory or API changed |
| AR/EN, RTL/LTR, accessibility | Existing server-side error/UI paths preserved; no new translation key or screen |

**Required acceptance:** exact-head Repository Policy, V2 Isolation, PHP Syntax/Pint/PHPStan/Composer audit/OpenAPI, AR/EN and full Laravel + MySQL/Redis Required CI **terminal success** before a SHA-guarded merge exclusively to `Foodex_V2`. Parent signed Setup/Update ZIP and historical real-data upgrade gates remain separately OPEN. No main, tag, release, or production delivery is implied by this atomic guard.

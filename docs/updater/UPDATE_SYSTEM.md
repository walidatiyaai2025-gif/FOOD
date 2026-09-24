# Update Center Framework

Dashboard target: **Settings > System Update**.

Mandatory sequence:

`Upload Package -> Validate Package -> Verify Signature/Hash -> Preflight -> Backup Files -> Backup Database -> Maintenance Mode -> Extract New Release -> Run Migrations -> Clear/Rebuild Cache -> Health Check -> Success`

Failure sequence:

`Log Failure -> Rollback Files -> Rollback DB when safely possible -> Restore Previous Version -> Exit Maintenance Mode -> Report Failure`

The foundation code defines pipeline stages and update package manifest only. M7 implementation adds privileged UI, package storage, current/target versions, release notes, compatibility, disk/PHP/Laravel checks, migration detection, backup/rollback orchestration, update logs/history and health reports.

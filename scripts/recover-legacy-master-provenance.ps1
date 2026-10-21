param(
    [string]$BackupFile = 'C:\Live\db-snapshots\foodex_BEFORE_SECOND_REHEARSAL_20261020_083544.sql',
    [string]$CurrentDatabase = 'foodex',
    [string]$RecoveryDatabase = 'foodex_provenance_recovery'
)

$ErrorActionPreference = 'Stop'
$MySql = 'C:\xampp\mysql\bin\mysql.exe'

if (-not (Test-Path $MySql)) {
    throw "mysql.exe not found: $MySql"
}

if (-not (Test-Path $BackupFile)) {
    throw "Safety backup not found: $BackupFile"
}

function Invoke-MySqlScalar {
    param([string]$Sql)

    $result = & $MySql -h 127.0.0.1 -P 3306 -u root --skip-password --batch --skip-column-names -e $Sql

    if ($LASTEXITCODE -ne 0) {
        throw "MySQL query failed: $Sql"
    }

    return [string]($result | Select-Object -First 1)
}

function Assert-Zero {
    param([string]$Name, [string]$Sql)

    $value = [int](Invoke-MySqlScalar $Sql)
    Write-Host "$Name = $value" -ForegroundColor Yellow

    if ($value -ne 0) {
        throw "MASTER PROVENANCE RECOVERY REFUSED: $Name has $value mismatch(es)"
    }
}

Write-Host ""
Write-Host "==================================================" -ForegroundColor Cyan
Write-Host " LEGACY MASTER PROVENANCE RECOVERY" -ForegroundColor Cyan
Write-Host "==================================================" -ForegroundColor Cyan
Write-Host "Backup : $BackupFile"
Write-Host "Current: $CurrentDatabase"
Write-Host "Temp   : $RecoveryDatabase"

& $MySql -h 127.0.0.1 -P 3306 -u root --skip-password -e "DROP DATABASE IF EXISTS $RecoveryDatabase; CREATE DATABASE $RecoveryDatabase CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

if ($LASTEXITCODE -ne 0) {
    throw "Could not create recovery database"
}

try {
    Write-Host ""
    Write-Host "Restoring safety backup into temporary database..." -ForegroundColor Cyan

    $restoreCommand = '"' + $MySql + '" -h 127.0.0.1 -P 3306 -u root --skip-password --default-character-set=utf8mb4 ' + $RecoveryDatabase + ' < "' + $BackupFile + '"'
    cmd.exe /d /c $restoreCommand

    if ($LASTEXITCODE -ne 0) {
        throw "Temporary safety-backup restore failed"
    }

    $mappingTableExists = [int](Invoke-MySqlScalar @"
SELECT COUNT(*)
FROM information_schema.tables
WHERE table_schema = '$RecoveryDatabase'
  AND table_name = 'legacy_import_mappings';
"@)

    if ($mappingTableExists -ne 1) {
        throw "Safety backup does not contain legacy_import_mappings"
    }

    Write-Host ""
    Write-Host "Validating protected master target identity before copying mappings..." -ForegroundColor Cyan

    Assert-Zero "warehouse identity mismatches" @"
SELECT COUNT(*)
FROM $RecoveryDatabase.legacy_import_mappings m
LEFT JOIN $RecoveryDatabase.warehouses old_row ON old_row.id = m.target_id
LEFT JOIN $CurrentDatabase.warehouses cur_row ON cur_row.id = m.target_id
WHERE m.source_system = 'foodex_sqlserver'
  AND m.target_table = 'warehouses'
  AND m.source_table IN ('wh_Stores','wh_Cars')
  AND (
      old_row.id IS NULL OR cur_row.id IS NULL
      OR COALESCE(old_row.code,'') <> COALESCE(cur_row.code,'')
  );
"@

    Assert-Zero "price-tier identity mismatches" @"
SELECT COUNT(*)
FROM $RecoveryDatabase.legacy_import_mappings m
LEFT JOIN $RecoveryDatabase.b2b_price_tiers old_row ON old_row.id = m.target_id
LEFT JOIN $CurrentDatabase.b2b_price_tiers cur_row ON cur_row.id = m.target_id
WHERE m.source_system = 'foodex_sqlserver'
  AND m.target_table = 'b2b_price_tiers'
  AND m.source_table = 'wh_PriceTypes'
  AND (
      old_row.id IS NULL OR cur_row.id IS NULL
      OR COALESCE(old_row.code,'') <> COALESCE(cur_row.code,'')
  );
"@

    Assert-Zero "customer identity mismatches" @"
SELECT COUNT(*)
FROM $RecoveryDatabase.legacy_import_mappings m
LEFT JOIN $RecoveryDatabase.customers old_row ON old_row.id = m.target_id
LEFT JOIN $CurrentDatabase.customers cur_row ON cur_row.id = m.target_id
WHERE m.source_system = 'foodex_sqlserver'
  AND m.target_table = 'customers'
  AND m.source_table = 'wh_Customers'
  AND (
      old_row.id IS NULL OR cur_row.id IS NULL
      OR COALESCE(old_row.name,'') <> COALESCE(cur_row.name,'')
      OR COALESCE(old_row.phone,'') <> COALESCE(cur_row.phone,'')
      OR COALESCE(old_row.email,'') <> COALESCE(cur_row.email,'')
  );
"@

    Assert-Zero "B2B customer identity mismatches" @"
SELECT COUNT(*)
FROM $RecoveryDatabase.legacy_import_mappings m
LEFT JOIN $RecoveryDatabase.b2b_customers old_row ON old_row.id = m.target_id
LEFT JOIN $CurrentDatabase.b2b_customers cur_row ON cur_row.id = m.target_id
WHERE m.source_system = 'foodex_sqlserver'
  AND m.target_table = 'b2b_customers'
  AND m.source_table = 'wh_Customers'
  AND (
      old_row.id IS NULL OR cur_row.id IS NULL
      OR COALESCE(old_row.legacy_customer_id,0) <> COALESCE(cur_row.legacy_customer_id,0)
  );
"@

    Assert-Zero "B2B account identity mismatches" @"
SELECT COUNT(*)
FROM $RecoveryDatabase.legacy_import_mappings m
LEFT JOIN $RecoveryDatabase.b2b_accounts old_row ON old_row.id = m.target_id
LEFT JOIN $CurrentDatabase.b2b_accounts cur_row ON cur_row.id = m.target_id
WHERE m.source_system = 'foodex_sqlserver'
  AND m.target_table = 'b2b_accounts'
  AND m.source_table = 'wh_Customers'
  AND (
      old_row.id IS NULL OR cur_row.id IS NULL
      OR COALESCE(old_row.customer_id,0) <> COALESCE(cur_row.customer_id,0)
  );
"@

    Assert-Zero "platform customer identity mismatches" @"
SELECT COUNT(*)
FROM $RecoveryDatabase.legacy_import_mappings m
LEFT JOIN $RecoveryDatabase.platform_customers old_row ON old_row.id = m.target_id
LEFT JOIN $CurrentDatabase.platform_customers cur_row ON cur_row.id = m.target_id
WHERE m.source_system = 'foodex_sqlserver'
  AND m.target_table = 'platform_customers'
  AND m.source_table = 'wh_Customers'
  AND (
      old_row.id IS NULL OR cur_row.id IS NULL
      OR COALESCE(old_row.legacy_customer_id,0) <> COALESCE(cur_row.legacy_customer_id,0)
  );
"@

    Assert-Zero "address identity mismatches" @"
SELECT COUNT(*)
FROM $RecoveryDatabase.legacy_import_mappings m
LEFT JOIN $RecoveryDatabase.addresses old_row ON old_row.id = m.target_id
LEFT JOIN $CurrentDatabase.addresses cur_row ON cur_row.id = m.target_id
WHERE m.source_system = 'foodex_sqlserver'
  AND m.target_table = 'addresses'
  AND m.source_table = 'wh_Customers.Address'
  AND (
      old_row.id IS NULL OR cur_row.id IS NULL
      OR COALESCE(old_row.customer_id,0) <> COALESCE(cur_row.customer_id,0)
      OR COALESCE(old_row.line1,'') <> COALESCE(cur_row.line1,'')
  );
"@

    Assert-Zero "supplier identity mismatches" @"
SELECT COUNT(*)
FROM $RecoveryDatabase.legacy_import_mappings m
LEFT JOIN $RecoveryDatabase.enterprise_suppliers old_row ON old_row.id = m.target_id
LEFT JOIN $CurrentDatabase.enterprise_suppliers cur_row ON cur_row.id = m.target_id
WHERE m.source_system = 'foodex_sqlserver'
  AND m.target_table = 'enterprise_suppliers'
  AND m.source_table = 'wh_Suppliers'
  AND (
      old_row.id IS NULL OR cur_row.id IS NULL
      OR COALESCE(old_row.code,'') <> COALESCE(cur_row.code,'')
  );
"@

    Write-Host ""
    Write-Host "Identity validation passed. Restoring protected master mappings only..." -ForegroundColor Green

    $copySql = @"
INSERT INTO $CurrentDatabase.legacy_import_mappings
(
    source_system,
    source_table,
    source_key,
    target_table,
    target_id,
    source_hash,
    metadata,
    last_run_id,
    created_at,
    updated_at
)
SELECT
    m.source_system,
    m.source_table,
    m.source_key,
    m.target_table,
    m.target_id,
    m.source_hash,
    m.metadata,
    NULL,
    m.created_at,
    NOW()
FROM $RecoveryDatabase.legacy_import_mappings m
WHERE m.source_system = 'foodex_sqlserver'
  AND (
      (m.source_table IN ('wh_Stores','wh_Cars') AND m.target_table = 'warehouses')
      OR (m.source_table = 'wh_Customers' AND m.target_table IN ('customers','b2b_customers','b2b_accounts','platform_customers'))
      OR (m.source_table = 'wh_PriceTypes' AND m.target_table = 'b2b_price_tiers')
      OR (m.source_table = 'wh_Customers.Address' AND m.target_table = 'addresses')
      OR (m.source_table = 'wh_Suppliers' AND m.target_table = 'enterprise_suppliers')
  )
ON DUPLICATE KEY UPDATE
    target_id = VALUES(target_id),
    source_hash = VALUES(source_hash),
    metadata = VALUES(metadata),
    last_run_id = NULL,
    updated_at = NOW();
"@

    & $MySql -h 127.0.0.1 -P 3306 -u root --skip-password -e $copySql

    if ($LASTEXITCODE -ne 0) {
        throw "Protected master mapping copy failed"
    }

    $recovered = [int](Invoke-MySqlScalar @"
SELECT COUNT(*)
FROM $CurrentDatabase.legacy_import_mappings
WHERE source_system = 'foodex_sqlserver'
  AND target_table IN (
      'warehouses',
      'customers',
      'b2b_customers',
      'b2b_accounts',
      'platform_customers',
      'b2b_price_tiers',
      'addresses',
      'enterprise_suppliers'
  );
"@)

    Write-Host ""
    Write-Host "Recovered protected master mappings: $recovered" -ForegroundColor Green

    if ($recovered -le 0) {
        throw "No protected master mappings were recovered"
    }

    Write-Host "MASTER PROVENANCE RECOVERY PASSED" -ForegroundColor Green
}
finally {
    & $MySql -h 127.0.0.1 -P 3306 -u root --skip-password -e "DROP DATABASE IF EXISTS $RecoveryDatabase;" | Out-Null
}

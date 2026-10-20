$ErrorActionPreference = 'Stop'

$RepoRoot = Split-Path -Parent $PSScriptRoot
$Backend = Join-Path $RepoRoot 'backend'
$EnvFile = Join-Path $Backend '.env'
$LogDir = 'C:\Live\db-snapshots'

if (-not (Test-Path $EnvFile)) {
    throw "Missing $EnvFile"
}

New-Item -ItemType Directory -Force $LogDir | Out-Null
$LogFile = Join-Path $LogDir ("LEGACY_REHEARSAL_" + (Get-Date -Format 'yyyyMMdd_HHmmss') + '.log')
Start-Transcript -Path $LogFile | Out-Null

function Set-EnvValue {
    param([string]$Key, [string]$Value)

    $content = [System.IO.File]::ReadAllText($EnvFile)
    $escaped = [Regex]::Escape($Key)

    if ($content -match "(?m)^$escaped=") {
        $content = [Regex]::Replace($content, "(?m)^$escaped=.*$", "$Key=$Value")
    } else {
        $content = $content.TrimEnd() + "`r`n$Key=$Value`r`n"
    }

    $utf8 = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($EnvFile, $content, $utf8)
}

function Run-Step {
    param([string]$Name, [scriptblock]$Command)

    Write-Host ""
    Write-Host "==================================================" -ForegroundColor Cyan
    Write-Host $Name -ForegroundColor Cyan
    Write-Host "==================================================" -ForegroundColor Cyan

    & $Command

    if ($LASTEXITCODE -ne 0) {
        throw "$Name FAILED"
    }

    Write-Host "PASSED: $Name" -ForegroundColor Green
}

try {
    Set-Location $Backend

    Run-Step 'PHP syntax - scoped reset service' {
        php -l app\Services\LegacyImportRehearsalResetService.php
    }

    Run-Step 'PHP syntax - legacy importer' {
        php -l app\Services\LegacyFoodexImportService.php
    }

    Run-Step 'PHP syntax - full source archive' {
        php -l app\Services\LegacyFullArchiveService.php
    }

    Run-Step 'PHP syntax - stock location resolver' {
        php -l app\Services\LegacyStockLocationResolver.php
    }

    Run-Step 'PHP syntax - invoice source materializer' {
        php -l app\Services\LegacyInvoiceSourceMaterializerService.php
    }

    Run-Step 'PHP syntax - stock reconciliation' {
        php -l app\Services\LegacyStockReconciliationService.php
    }

    Run-Step 'PHP syntax - legacy routes' {
        php -l routes\legacy_import.php
    }

    # Direct read-only legacy SQL Server source.
    Set-EnvValue 'LEGACY_DB_HOST' '172.30.88.33'
    Set-EnvValue 'LEGACY_DB_PORT' '1433'
    Set-EnvValue 'LEGACY_DB_DATABASE' 'Foodex'
    Set-EnvValue 'LEGACY_DB_ENCRYPT' 'no'
    Set-EnvValue 'LEGACY_DB_TRUST_CERTIFICATE' 'yes'
    Set-EnvValue 'LEGACY_DB_QUERY_TIMEOUT' '300'
    Set-EnvValue 'LEGACY_DB_DETAIL_CHUNK' '2000'
    Set-EnvValue 'LEGACY_DB_RETRY_ATTEMPTS' '5'
    Set-EnvValue 'FOODEX_ALLOW_DESTRUCTIVE_DB_COMMANDS' 'false'

    Run-Step 'Clear Laravel caches' {
        php artisan optimize:clear
    }

    Run-Step 'Direct SQL Server connection probe' {
        php artisan foodex:legacy-preflight --json | Out-Null
    }

    # Additive schema upgrade only. Never use migrate:fresh/reset/wipe.
    Run-Step 'Apply additive migrations' {
        php artisan migrate --force
    }

    Run-Step 'Full SQL Server archive dry-run' {
        php artisan foodex:legacy-archive-all
    }

    Run-Step 'Full SQL Server archive apply' {
        php artisan foodex:legacy-archive-all --apply
    }

    Run-Step 'Full SQL Server coverage verify' {
        php artisan foodex:legacy-archive-verify
    }

    Run-Step 'Scoped reset preview' {
        php artisan foodex:legacy-rehearsal-reset
    }

    Run-Step 'Scoped reset apply' {
        php artisan foodex:legacy-rehearsal-reset --apply --confirm="RESET LEGACY IMPORT SCOPE"
    }

    Run-Step 'Direct SQL Server preflight' {
        php artisan foodex:legacy-preflight
    }

    $preflightJson = php artisan foodex:legacy-preflight --json
    if ($LASTEXITCODE -ne 0) {
        throw 'Machine-readable legacy preflight FAILED'
    }

    $preflight = $preflightJson | ConvertFrom-Json
    if (-not ($preflight.target.clean_baseline_ready -or $preflight.target.legacy_upgrade_ready -or $preflight.target.legacy_masters_rerun_ready)) {
        throw 'Target is not ready after scoped reset. Refusing to import.'
    }

    Run-Step 'Masters dry-run' {
        php artisan foodex:legacy-import masters
    }
    Run-Step 'Masters apply' {
        php artisan foodex:legacy-import masters --apply
    }

    Run-Step 'Extended masters dry-run' {
        php artisan foodex:legacy-import extended-masters
    }
    Run-Step 'Extended masters apply' {
        php artisan foodex:legacy-import extended-masters --apply
    }

    Run-Step 'Stock dry-run' {
        php artisan foodex:legacy-import stock
    }
    Run-Step 'Stock apply' {
        php artisan foodex:legacy-import stock --apply
    }
    Run-Step 'Stock verify' {
        php artisan foodex:legacy-stock-verify
    }

    Run-Step 'Stock source-to-target reconcile' {
        php artisan foodex:legacy-stock-reconcile
    }

    Run-Step 'Sales history dry-run' {
        php artisan foodex:legacy-import sales-history
    }
    Run-Step 'Sales history apply' {
        php artisan foodex:legacy-import sales-history --apply
    }
    Run-Step 'Sales history verify' {
        php artisan foodex:legacy-sales-history-verify
    }

    Run-Step 'Invoice extra materialization' {
        php artisan foodex:legacy-invoice-extras --apply
    }

    Run-Step 'Invoice extra verification' {
        php artisan foodex:legacy-invoice-extras-verify
    }

    Run-Step 'Purchase history dry-run' {
        php artisan foodex:legacy-import purchase-history
    }
    Run-Step 'Purchase history apply' {
        php artisan foodex:legacy-import purchase-history --apply
    }
    Run-Step 'Purchase history verify' {
        php artisan foodex:legacy-purchase-history-verify
    }

    Run-Step 'Operational history dry-run' {
        php artisan foodex:legacy-import operational-history
    }
    Run-Step 'Operational history apply' {
        php artisan foodex:legacy-import operational-history --apply
    }
    Run-Step 'Operational history verify' {
        php artisan foodex:legacy-operational-history-verify
    }

    Run-Step 'Finance history dry-run' {
        php artisan foodex:legacy-import finance-history
    }
    Run-Step 'Finance history apply' {
        php artisan foodex:legacy-import finance-history --apply
    }
    Run-Step 'Finance history verify' {
        php artisan foodex:legacy-finance-history-verify
    }

    Run-Step 'Customer 360 dry-run' {
        php artisan foodex:legacy-customer360-backfill
    }
    Run-Step 'Customer 360 apply' {
        php artisan foodex:legacy-customer360-backfill --apply
    }
    Run-Step 'Customer 360 idempotency check' {
        php artisan foodex:legacy-customer360-backfill --json
    }

    Run-Step 'FINAL stock verify' {
        php artisan foodex:legacy-stock-verify
    }

    Run-Step 'FINAL stock source-to-target reconcile' {
        php artisan foodex:legacy-stock-reconcile
    }
    Run-Step 'FINAL sales verify' {
        php artisan foodex:legacy-sales-history-verify
    }
    Run-Step 'FINAL purchase verify' {
        php artisan foodex:legacy-purchase-history-verify
    }
    Run-Step 'FINAL operational verify' {
        php artisan foodex:legacy-operational-history-verify
    }
    Run-Step 'FINAL finance verify' {
        php artisan foodex:legacy-finance-history-verify
    }

    Run-Step 'FINAL full SQL Server coverage verify' {
        php artisan foodex:legacy-archive-verify
    }

    Write-Host ""
    Write-Host "==================================================" -ForegroundColor Green
    Write-Host " FOODEX LEGACY REHEARSAL PASSED" -ForegroundColor Green
    Write-Host " Source: 172.30.88.33 / Foodex" -ForegroundColor Green
    Write-Host " Log: $LogFile" -ForegroundColor Green
    Write-Host "==================================================" -ForegroundColor Green
}
catch {
    Write-Host ""
    Write-Host "REHEARSAL STOPPED SAFELY" -ForegroundColor Red
    Write-Host $_.Exception.Message -ForegroundColor Red
    Write-Host "Log: $LogFile" -ForegroundColor Yellow
    throw
}
finally {
    try { Stop-Transcript | Out-Null } catch {}
}

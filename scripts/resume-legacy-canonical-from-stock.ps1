$ErrorActionPreference = 'Stop'

$RepoRoot = Split-Path -Parent $PSScriptRoot
$Backend = Join-Path $RepoRoot 'backend'
$EnvFile = Join-Path $Backend '.env'
$LogDir = 'C:\Live\db-snapshots'

if (-not (Test-Path $EnvFile)) {
    throw "Missing $EnvFile"
}

New-Item -ItemType Directory -Force $LogDir | Out-Null
$LogFile = Join-Path $LogDir ("LEGACY_CANONICAL_RESUME_FROM_STOCK_" + (Get-Date -Format 'yyyyMMdd_HHmmss') + '.log')
Start-Transcript -Path $LogFile | Out-Null

function Set-EnvValue {
    param([string]$Key, [string]$Value)

    $content = [System.IO.File]::ReadAllText($EnvFile)
    $escaped = [Regex]::Escape($Key)

    if ($content -match "(?m)^$escaped=") {
        $content = [Regex]::Replace($content, "(?m)^$escaped=.*$", "$Key=$Value")
    } else {
        $content = $content.TrimEnd() + [Environment]::NewLine + "$Key=$Value" + [Environment]::NewLine
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

    Set-EnvValue 'LEGACY_DB_HOST' '172.30.88.33'
    Set-EnvValue 'LEGACY_DB_PORT' '1433'
    Set-EnvValue 'LEGACY_DB_DATABASE' 'Foodex'
    Set-EnvValue 'LEGACY_DB_ENCRYPT' 'no'
    Set-EnvValue 'LEGACY_DB_TRUST_CERTIFICATE' 'yes'
    Set-EnvValue 'LEGACY_DB_QUERY_TIMEOUT' '300'
    Set-EnvValue 'LEGACY_DB_DETAIL_CHUNK' '2000'
    Set-EnvValue 'LEGACY_DB_RETRY_ATTEMPTS' '5'
    Set-EnvValue 'FOODEX_ALLOW_DESTRUCTIVE_DB_COMMANDS' 'false'

    Run-Step 'PHP syntax - stock location resolver' {
        php -l app\Services\LegacyStockLocationResolver.php
    }

    Run-Step 'PHP syntax - invoice source materializer' {
        php -l app\Services\LegacyInvoiceSourceMaterializerService.php
    }

    Run-Step 'PHP syntax - business impact materializer' {
        php -l app\Services\LegacyBusinessImpactMaterializerService.php
    }

    Run-Step 'PHP syntax - Van native history' {
        php -l app\Services\LegacyVanInventoryHistoryMaterializerService.php
    }

    Run-Step 'PHP syntax - importer' {
        php -l app\Services\LegacyFoodexImportService.php
    }

    Run-Step 'PHP syntax - stock reconciliation' {
        php -l app\Services\LegacyStockReconciliationService.php
    }

    Run-Step 'PHP syntax - legacy routes' {
        php -l routes\legacy_import.php
    }

    Run-Step 'Clear Laravel caches' {
        php artisan optimize:clear
    }

    Run-Step 'Verify existing full SQL Server archive' {
        php artisan foodex:legacy-archive-verify
    }

    Run-Step 'Stock dry-run' {
        php artisan foodex:legacy-import stock
    }

    Run-Step 'Stock apply' {
        php artisan foodex:legacy-import stock --apply
    }

    Run-Step 'Stock structural verify' {
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

    Run-Step 'Native Van history dry-run' {
        php artisan foodex:legacy-van-history
    }

    Run-Step 'Native Van history apply' {
        php artisan foodex:legacy-van-history --apply
    }

    Run-Step 'Native Van history verify' {
        php artisan foodex:legacy-van-history-verify
    }

    Run-Step 'Business-impact materialization dry-run' {
        php artisan foodex:legacy-business-materialize
    }

    Run-Step 'Business-impact materialization apply' {
        php artisan foodex:legacy-business-materialize --apply
    }

    Run-Step 'Business-impact coverage verify' {
        php artisan foodex:legacy-business-verify
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

    Run-Step 'FINAL stock structural verify' {
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
    Write-Host " FOODEX CANONICAL IMPORT RESUME FROM STOCK PASSED" -ForegroundColor Green
    Write-Host " Log: $LogFile" -ForegroundColor Green
    Write-Host "==================================================" -ForegroundColor Green
}
catch {
    Write-Host ""
    Write-Host "CANONICAL RESUME FROM STOCK STOPPED SAFELY" -ForegroundColor Red
    Write-Host $_.Exception.Message -ForegroundColor Red
    Write-Host "Log: $LogFile" -ForegroundColor Yellow
    throw
}
finally {
    try { Stop-Transcript | Out-Null } catch {}
}

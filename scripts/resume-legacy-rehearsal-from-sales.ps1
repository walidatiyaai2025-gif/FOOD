$ErrorActionPreference = 'Stop'

$RepoRoot = Split-Path -Parent $PSScriptRoot
$Backend = Join-Path $RepoRoot 'backend'
$EnvFile = Join-Path $Backend '.env'
$LogDir = 'C:\Live\db-snapshots'

if (-not (Test-Path $EnvFile)) {
    throw "Missing $EnvFile"
}

New-Item -ItemType Directory -Force $LogDir | Out-Null
$LogFile = Join-Path $LogDir ("LEGACY_REHEARSAL_RESUME_SALES_" + (Get-Date -Format 'yyyyMMdd_HHmmss') + '.log')
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

    Set-EnvValue 'LEGACY_DB_HOST' '172.30.88.33'
    Set-EnvValue 'LEGACY_DB_PORT' '1433'
    Set-EnvValue 'LEGACY_DB_DATABASE' 'Foodex'
    Set-EnvValue 'LEGACY_DB_ENCRYPT' 'no'
    Set-EnvValue 'LEGACY_DB_TRUST_CERTIFICATE' 'yes'
    Set-EnvValue 'FOODEX_ALLOW_DESTRUCTIVE_DB_COMMANDS' 'false'

    Run-Step 'PHP syntax - legacy importer' {
        php -l app\Services\LegacyFoodexImportService.php
    }

    Run-Step 'Clear Laravel caches' {
        php artisan optimize:clear
    }

    # Prove the already-completed stock phase is intact. Do not reset/reapply it.
    Run-Step 'Existing stock verify' {
        php artisan foodex:legacy-stock-verify
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

    Write-Host ""
    Write-Host "==================================================" -ForegroundColor Green
    Write-Host " FOODEX LEGACY REHEARSAL RESUME PASSED" -ForegroundColor Green
    Write-Host " Source: 172.30.88.33 / Foodex" -ForegroundColor Green
    Write-Host " Log: $LogFile" -ForegroundColor Green
    Write-Host "==================================================" -ForegroundColor Green
}
catch {
    Write-Host ""
    Write-Host "REHEARSAL RESUME STOPPED SAFELY" -ForegroundColor Red
    Write-Host $_.Exception.Message -ForegroundColor Red
    Write-Host "Log: $LogFile" -ForegroundColor Yellow
    throw
}
finally {
    try { Stop-Transcript | Out-Null } catch {}
}

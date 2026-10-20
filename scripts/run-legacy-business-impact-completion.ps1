$ErrorActionPreference = 'Stop'

$RepoRoot = Split-Path -Parent $PSScriptRoot
$Backend = Join-Path $RepoRoot 'backend'
$LogDir = 'C:\Live\db-snapshots'

New-Item -ItemType Directory -Force $LogDir | Out-Null
$LogFile = Join-Path $LogDir ("LEGACY_BUSINESS_IMPACT_COMPLETION_" + (Get-Date -Format 'yyyyMMdd_HHmmss') + '.log')
Start-Transcript -Path $LogFile | Out-Null

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

    Run-Step 'PHP syntax - business impact materializer' {
        php -l app\Services\LegacyBusinessImpactMaterializerService.php
    }

    Run-Step 'PHP syntax - canonical coverage service' {
        php -l app\Services\LegacyCanonicalCoverageService.php
    }

    Run-Step 'PHP syntax - legacy routes' {
        php -l routes\legacy_import.php
    }

    Run-Step 'Clear Laravel caches' {
        php artisan optimize:clear
    }

    Run-Step 'Apply additive migrations' {
        php artisan migrate --force
    }

    Run-Step 'Verify full SQL archive before business materialization' {
        php artisan foodex:legacy-archive-verify
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

    Run-Step 'Canonical coverage after business materialization' {
        php artisan foodex:legacy-canonical-coverage
    }

    Run-Step 'Final stock reconciliation' {
        php artisan foodex:legacy-stock-reconcile
    }

    Run-Step 'Final sales/invoice verification' {
        php artisan foodex:legacy-sales-history-verify
    }

    Run-Step 'Final full archive verification' {
        php artisan foodex:legacy-archive-verify
    }

    Run-Step 'Clear application caches' {
        php artisan optimize:clear
    }

    Write-Host ""
    Write-Host "==================================================" -ForegroundColor Green
    Write-Host " FOODEX BUSINESS-IMPACT COMPLETION PASSED" -ForegroundColor Green
    Write-Host " No reset or historical re-import was performed." -ForegroundColor Green
    Write-Host " Log: $LogFile" -ForegroundColor Green
    Write-Host "==================================================" -ForegroundColor Green
}
catch {
    Write-Host ""
    Write-Host "BUSINESS-IMPACT COMPLETION STOPPED SAFELY" -ForegroundColor Red
    Write-Host $_.Exception.Message -ForegroundColor Red
    Write-Host "Log: $LogFile" -ForegroundColor Yellow
    throw
}
finally {
    try { Stop-Transcript | Out-Null } catch {}
}

$ErrorActionPreference = 'Stop'

$RepoRoot = Split-Path -Parent $PSScriptRoot
$Backend = Join-Path $RepoRoot 'backend'
$LogDir = 'C:\Live\db-snapshots'

New-Item -ItemType Directory -Force $LogDir | Out-Null
$LogFile = Join-Path $LogDir ("LEGACY_POST_IMPORT_COMPLETION_" + (Get-Date -Format 'yyyyMMdd_HHmmss') + '.log')
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

    Run-Step 'PHP syntax - invoice source materializer' {
        php -l app\Services\LegacyInvoiceSourceMaterializerService.php
    }

    Run-Step 'PHP syntax - canonical coverage service' {
        php -l app\Services\LegacyCanonicalCoverageService.php
    }

    Run-Step 'PHP syntax - invoice service' {
        php -l app\Services\InvoiceService.php
    }

    Run-Step 'PHP syntax - B2B workspace controller' {
        php -l app\Http\Controllers\Admin\B2bWorkspaceController.php
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

    Run-Step 'Verify full SQL archive' {
        php artisan foodex:legacy-archive-verify
    }

    Run-Step 'Verify canonical stock from SQL Server' {
        php artisan foodex:legacy-stock-reconcile
    }

    Run-Step 'Verify sales and invoices' {
        php artisan foodex:legacy-sales-history-verify
    }

    Run-Step 'Invoice extra dry-run' {
        php artisan foodex:legacy-invoice-extras
    }

    Run-Step 'Invoice extra materialization' {
        php artisan foodex:legacy-invoice-extras --apply
    }

    Run-Step 'Invoice extra verification' {
        php artisan foodex:legacy-invoice-extras-verify
    }

    Run-Step 'Canonical coverage report' {
        php artisan foodex:legacy-canonical-coverage
    }

    Run-Step 'Clear application caches after completion' {
        php artisan optimize:clear
    }

    Write-Host ""
    Write-Host "==================================================" -ForegroundColor Green
    Write-Host " FOODEX POST-IMPORT COMPLETION PASSED" -ForegroundColor Green
    Write-Host " No reset or historical re-import was performed." -ForegroundColor Green
    Write-Host " Log: $LogFile" -ForegroundColor Green
    Write-Host "==================================================" -ForegroundColor Green
}
catch {
    Write-Host ""
    Write-Host "POST-IMPORT COMPLETION STOPPED SAFELY" -ForegroundColor Red
    Write-Host $_.Exception.Message -ForegroundColor Red
    Write-Host "Log: $LogFile" -ForegroundColor Yellow
    throw
}
finally {
    try { Stop-Transcript | Out-Null } catch {}
}

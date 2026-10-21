$ErrorActionPreference = 'Stop'
$RepoRoot = Split-Path -Parent $PSScriptRoot
$Backend = Join-Path $RepoRoot 'backend'
$Common = Join-Path $PSScriptRoot 'legacy\LegacyPipeline.Common.ps1'
$NativeRegistryFile = Join-Path $PSScriptRoot 'legacy\native-phases.json'
$LogDir = 'C:\Live\db-snapshots'

if (-not (Test-Path $Common)) { throw "Missing $Common" }
if (-not (Test-Path $NativeRegistryFile)) { throw "Missing $NativeRegistryFile" }
. $Common
$NativeRegistry = Get-Content -Raw $NativeRegistryFile | ConvertFrom-Json

New-Item -ItemType Directory -Force $LogDir | Out-Null
$stamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$LogFile = Join-Path $LogDir ("LEGACY_NATIVE_PROMOTIONS_" + $stamp + '.log')
Start-Transcript -Path $LogFile | Out-Null

try {
    Set-Location $Backend

    foreach ($phase in $NativeRegistry.phases | Where-Object { $_.enabled }) {
        foreach ($lintFile in $phase.lint_files) {
            Invoke-FoodexStep ("Native phase lint: " + $phase.name + " :: " + $lintFile) {
                php -l $lintFile
            }
        }
    }

    Invoke-FoodexStep 'Legacy routes syntax' { php -l routes\legacy_import.php }
    Invoke-FoodexStep 'Clear Laravel caches' { php artisan optimize:clear }
    Invoke-FoodexStep 'Apply additive migrations' { php artisan migrate --force }
    Invoke-FoodexStep 'Verify full SQL archive' { php artisan foodex:legacy-archive-verify }

    foreach ($phase in $NativeRegistry.phases | Where-Object { $_.enabled }) {
        Invoke-FoodexStep ("Native phase dry-run: " + $phase.name) {
            $artisanArgs = @($phase.dry_run)
            php artisan @artisanArgs
        }

        Invoke-FoodexStep ("Native phase apply: " + $phase.name) {
            $artisanArgs = @($phase.apply)
            php artisan @artisanArgs
        }

        Invoke-FoodexStep ("Native phase verify: " + $phase.name) {
            $artisanArgs = @($phase.verify)
            php artisan @artisanArgs
        }
    }

    Invoke-FoodexStep 'Profile legacy fixed assets (read-only)' { php artisan foodex:legacy-finance-assets-profile }

    Invoke-FoodexStep 'Rebuild business-impact extension after native promotions' { php artisan foodex:legacy-business-materialize --apply }
    Invoke-FoodexStep 'Business-impact coverage verify' { php artisan foodex:legacy-business-verify }
    Invoke-FoodexStep 'Canonical coverage report' { php artisan foodex:legacy-canonical-coverage }
    Invoke-FoodexStep 'Stock source-to-target reconcile' { php artisan foodex:legacy-stock-reconcile }
    Invoke-FoodexStep 'Sales/invoice verify' { php artisan foodex:legacy-sales-history-verify }
    Invoke-FoodexStep 'Final full SQL archive verify' { php artisan foodex:legacy-archive-verify }
    Invoke-FoodexStep 'Clear Laravel caches after promotions' { php artisan optimize:clear }

    Write-Host ""
    Write-Host "==================================================" -ForegroundColor Green
    Write-Host " FOODEX NATIVE PROMOTIONS PASSED" -ForegroundColor Green
    Write-Host " Registry version: $($NativeRegistry.version)" -ForegroundColor Green
    Write-Host " Log: $LogFile" -ForegroundColor Green
    Write-Host "==================================================" -ForegroundColor Green
}
catch {
    Write-Host ""
    Write-Host "NATIVE PROMOTIONS STOPPED SAFELY" -ForegroundColor Red
    Write-Host $_.Exception.Message -ForegroundColor Red
    Write-Host "Log: $LogFile" -ForegroundColor Yellow
    throw
}
finally {
    try { Stop-Transcript | Out-Null } catch {}
}
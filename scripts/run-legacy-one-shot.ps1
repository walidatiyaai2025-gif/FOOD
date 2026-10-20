param(
    [string]$SourceHost = '172.30.88.33',
    [int]$SourcePort = 1433,
    [string]$SourceDatabase = 'Foodex',
    [string]$TargetDatabase = 'foodex',
    [string]$MySql = 'C:\xampp\mysql\bin\mysql.exe',
    [string]$MySqlDump = 'C:\xampp\mysql\bin\mysqldump.exe',
    [string]$LogDir = 'C:\Live\db-snapshots',
    [switch]$SkipSafetyBackup
)

$ErrorActionPreference = 'Stop'
$RepoRoot = Split-Path -Parent $PSScriptRoot
$Backend = Join-Path $RepoRoot 'backend'
$EnvFile = Join-Path $Backend '.env'
$Common = Join-Path $PSScriptRoot 'legacy\LegacyPipeline.Common.ps1'
$NativeRegistryFile = Join-Path $PSScriptRoot 'legacy\native-phases.json'

if (-not (Test-Path $Common)) { throw "Missing reusable pipeline helpers: $Common" }
if (-not (Test-Path $NativeRegistryFile)) { throw "Missing native phase registry: $NativeRegistryFile" }
. $Common
$NativeRegistry = Get-Content -Raw $NativeRegistryFile | ConvertFrom-Json

if (-not (Test-Path $EnvFile)) { throw "Missing $EnvFile" }
if (-not (Test-Path $MySql)) { throw "Missing mysql.exe: $MySql" }
if (-not (Test-Path $MySqlDump)) { throw "Missing mysqldump.exe: $MySqlDump" }

New-Item -ItemType Directory -Force $LogDir | Out-Null
$stamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$LogFile = Join-Path $LogDir ("LEGACY_ONE_SHOT_" + $stamp + '.log')
$ManifestFile = Join-Path $LogDir ("LEGACY_ONE_SHOT_" + $stamp + '.json')
$SafetyBackup = $null
$startedAt = Get-Date

Start-Transcript -Path $LogFile | Out-Null

try {
    Set-Location $Backend

    Set-FoodexEnvValue -EnvFile $EnvFile -Key 'DB_CONNECTION' -Value 'mysql'
    Set-FoodexEnvValue -EnvFile $EnvFile -Key 'DB_HOST' -Value '127.0.0.1'
    Set-FoodexEnvValue -EnvFile $EnvFile -Key 'DB_PORT' -Value '3306'
    Set-FoodexEnvValue -EnvFile $EnvFile -Key 'DB_DATABASE' -Value $TargetDatabase
    Set-FoodexEnvValue -EnvFile $EnvFile -Key 'DB_USERNAME' -Value 'root'
    Set-FoodexEnvValue -EnvFile $EnvFile -Key 'DB_PASSWORD' -Value ''
    Set-FoodexEnvValue -EnvFile $EnvFile -Key 'LEGACY_DB_HOST' -Value $SourceHost
    Set-FoodexEnvValue -EnvFile $EnvFile -Key 'LEGACY_DB_PORT' -Value ([string]$SourcePort)
    Set-FoodexEnvValue -EnvFile $EnvFile -Key 'LEGACY_DB_DATABASE' -Value $SourceDatabase
    Set-FoodexEnvValue -EnvFile $EnvFile -Key 'LEGACY_DB_ENCRYPT' -Value 'no'
    Set-FoodexEnvValue -EnvFile $EnvFile -Key 'LEGACY_DB_TRUST_CERTIFICATE' -Value 'yes'
    Set-FoodexEnvValue -EnvFile $EnvFile -Key 'LEGACY_DB_QUERY_TIMEOUT' -Value '300'
    Set-FoodexEnvValue -EnvFile $EnvFile -Key 'LEGACY_DB_DETAIL_CHUNK' -Value '2000'
    Set-FoodexEnvValue -EnvFile $EnvFile -Key 'LEGACY_DB_RETRY_ATTEMPTS' -Value '5'
    Set-FoodexEnvValue -EnvFile $EnvFile -Key 'FOODEX_ALLOW_DESTRUCTIVE_DB_COMMANDS' -Value 'false'

    if (-not $SkipSafetyBackup) {
        Invoke-FoodexStep 'Create safety backup' {
            $script:SafetyBackup = New-FoodexBackup -DumpExe $MySqlDump -Database $TargetDatabase -LogDir $LogDir
            Write-Host "Safety backup: $script:SafetyBackup" -ForegroundColor Green
        }
    }

    Invoke-FoodexStep 'PHP syntax - importer' { php -l app\Services\LegacyFoodexImportService.php }
    Invoke-FoodexStep 'PHP syntax - full archive' { php -l app\Services\LegacyFullArchiveService.php }
    Invoke-FoodexStep 'PHP syntax - stock resolver' { php -l app\Services\LegacyStockLocationResolver.php }
    Invoke-FoodexStep 'PHP syntax - stock reconciliation' { php -l app\Services\LegacyStockReconciliationService.php }
    Invoke-FoodexStep 'PHP syntax - invoice extensions' { php -l app\Services\LegacyInvoiceSourceMaterializerService.php }
    Invoke-FoodexStep 'PHP syntax - business impact materializer' { php -l app\Services\LegacyBusinessImpactMaterializerService.php }
    Invoke-FoodexStep 'PHP syntax - canonical coverage' { php -l app\Services\LegacyCanonicalCoverageService.php }
    Invoke-FoodexStep 'PHP syntax - reset service' { php -l app\Services\LegacyImportRehearsalResetService.php }
    Invoke-FoodexStep 'PHP syntax - legacy routes' { php -l routes\legacy_import.php }
    foreach ($phase in $NativeRegistry.phases | Where-Object { $_.enabled }) {
        foreach ($lintFile in $phase.lint_files) {
            Invoke-FoodexStep ("Native phase lint: " + $phase.name + " :: " + $lintFile) {
                php -l $lintFile
            }
        }
    }

    Invoke-FoodexStep 'Clear Laravel caches before one-shot' { php artisan optimize:clear }
    Invoke-FoodexStep 'Apply additive migrations' { php artisan migrate --force }
    Invoke-FoodexStep 'Direct SQL Server preflight before archive' { php artisan foodex:legacy-preflight }

    Invoke-FoodexStep 'Full SQL Server archive dry-run' { php artisan foodex:legacy-archive-all }
    Invoke-FoodexStep 'Full SQL Server archive apply' { php artisan foodex:legacy-archive-all --apply }
    Invoke-FoodexStep 'Full SQL Server archive verify' { php artisan foodex:legacy-archive-verify }

    Invoke-FoodexStep 'Scoped reset preview' { php artisan foodex:legacy-rehearsal-reset }
    Invoke-FoodexStep 'Scoped reset apply' { php artisan foodex:legacy-rehearsal-reset --apply --confirm='RESET LEGACY IMPORT SCOPE' }

    Invoke-FoodexStep 'Post-reset preflight' { php artisan foodex:legacy-preflight }
    $preflightJson = php artisan foodex:legacy-preflight --json
    if ($LASTEXITCODE -ne 0) { throw 'Machine-readable post-reset preflight FAILED' }
    $preflight = $preflightJson | ConvertFrom-Json
    if (-not ($preflight.target.clean_baseline_ready -or $preflight.target.legacy_upgrade_ready -or $preflight.target.legacy_masters_rerun_ready)) {
        throw 'Target is not import-ready after scoped reset.'
    }

    Invoke-FoodexStep 'Masters dry-run' { php artisan foodex:legacy-import masters }
    Invoke-FoodexStep 'Masters apply' { php artisan foodex:legacy-import masters --apply }
    Invoke-FoodexStep 'Extended masters dry-run' { php artisan foodex:legacy-import extended-masters }
    Invoke-FoodexStep 'Extended masters apply' { php artisan foodex:legacy-import extended-masters --apply }

    Invoke-FoodexStep 'Stock dry-run' { php artisan foodex:legacy-import stock }
    Invoke-FoodexStep 'Stock apply' { php artisan foodex:legacy-import stock --apply }
    Invoke-FoodexStep 'Stock structural verify' { php artisan foodex:legacy-stock-verify }
    Invoke-FoodexStep 'Stock source-to-target reconcile' { php artisan foodex:legacy-stock-reconcile }

    Invoke-FoodexStep 'Sales history dry-run' { php artisan foodex:legacy-import sales-history }
    Invoke-FoodexStep 'Sales history apply' { php artisan foodex:legacy-import sales-history --apply }
    Invoke-FoodexStep 'Sales history verify' { php artisan foodex:legacy-sales-history-verify }

    Invoke-FoodexStep 'Purchase history dry-run' { php artisan foodex:legacy-import purchase-history }
    Invoke-FoodexStep 'Purchase history apply' { php artisan foodex:legacy-import purchase-history --apply }
    Invoke-FoodexStep 'Purchase history verify' { php artisan foodex:legacy-purchase-history-verify }

    Invoke-FoodexStep 'Operational history dry-run' { php artisan foodex:legacy-import operational-history }
    Invoke-FoodexStep 'Operational history apply' { php artisan foodex:legacy-import operational-history --apply }
    Invoke-FoodexStep 'Operational history verify' { php artisan foodex:legacy-operational-history-verify }

    Invoke-FoodexStep 'Finance history dry-run' { php artisan foodex:legacy-import finance-history }
    Invoke-FoodexStep 'Finance history apply' { php artisan foodex:legacy-import finance-history --apply }
    Invoke-FoodexStep 'Finance history verify' { php artisan foodex:legacy-finance-history-verify }

    Invoke-FoodexStep 'Customer 360 dry-run' { php artisan foodex:legacy-customer360-backfill }
    Invoke-FoodexStep 'Customer 360 apply' { php artisan foodex:legacy-customer360-backfill --apply }
    Invoke-FoodexStep 'Customer 360 idempotency verify' { php artisan foodex:legacy-customer360-backfill --json }

    Invoke-FoodexStep 'Invoice extra dry-run' { php artisan foodex:legacy-invoice-extras }
    Invoke-FoodexStep 'Invoice extra apply' { php artisan foodex:legacy-invoice-extras --apply }
    Invoke-FoodexStep 'Invoice extra verify' { php artisan foodex:legacy-invoice-extras-verify }

    # Native-domain phases are registry-driven. Future domains are added only to native-phases.json.
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

    Invoke-FoodexStep 'Business-impact extension dry-run' { php artisan foodex:legacy-business-materialize }
    Invoke-FoodexStep 'Business-impact extension apply' { php artisan foodex:legacy-business-materialize --apply }
    Invoke-FoodexStep 'Business-impact coverage verify' { php artisan foodex:legacy-business-verify }
    Invoke-FoodexStep 'Canonical coverage report' { php artisan foodex:legacy-canonical-coverage }

    Invoke-FoodexStep 'FINAL stock structural verify' { php artisan foodex:legacy-stock-verify }
    Invoke-FoodexStep 'FINAL stock source-to-target reconcile' { php artisan foodex:legacy-stock-reconcile }
    Invoke-FoodexStep 'FINAL sales verify' { php artisan foodex:legacy-sales-history-verify }
    Invoke-FoodexStep 'FINAL purchase verify' { php artisan foodex:legacy-purchase-history-verify }
    Invoke-FoodexStep 'FINAL operational verify' { php artisan foodex:legacy-operational-history-verify }
    Invoke-FoodexStep 'FINAL finance verify' { php artisan foodex:legacy-finance-history-verify }
    foreach ($phase in $NativeRegistry.phases | Where-Object { $_.enabled }) {
        Invoke-FoodexStep ("FINAL native phase verify: " + $phase.name) {
            $artisanArgs = @($phase.verify)
            php artisan @artisanArgs
        }
    }
    Invoke-FoodexStep 'FINAL business-impact verify' { php artisan foodex:legacy-business-verify }
    Invoke-FoodexStep 'FINAL full SQL archive verify' { php artisan foodex:legacy-archive-verify }
    Invoke-FoodexStep 'Clear Laravel caches after one-shot' { php artisan optimize:clear }

    $gitHead = (git -C $RepoRoot rev-parse HEAD).Trim()
    $finishedAt = Get-Date
    $manifest = [ordered]@{
        status = 'passed'
        started_at = $startedAt.ToString('o')
        finished_at = $finishedAt.ToString('o')
        git_head = $gitHead
        source = [ordered]@{ host = $SourceHost; port = $SourcePort; database = $SourceDatabase }
        target = [ordered]@{ database = $TargetDatabase }
        safety_backup = $SafetyBackup
        log_file = $LogFile
        reusable_entrypoint = 'scripts/run-legacy-one-shot.ps1'
        native_registry_version = $NativeRegistry.version
        native_phases = @($NativeRegistry.phases | Where-Object { $_.enabled } | ForEach-Object { $_.id })
    }
    $manifest | ConvertTo-Json -Depth 6 | Set-Content -Path $ManifestFile -Encoding UTF8

    Write-Host ""
    Write-Host "==================================================" -ForegroundColor Green
    Write-Host " FOODEX LEGACY ONE-SHOT PASSED" -ForegroundColor Green
    Write-Host " Backup: $SafetyBackup" -ForegroundColor Green
    Write-Host " Log: $LogFile" -ForegroundColor Green
    Write-Host " Manifest: $ManifestFile" -ForegroundColor Green
    Write-Host "==================================================" -ForegroundColor Green
}
catch {
    $gitHead = try { (git -C $RepoRoot rev-parse HEAD).Trim() } catch { $null }
    $failed = [ordered]@{
        status = 'failed'
        started_at = $startedAt.ToString('o')
        failed_at = (Get-Date).ToString('o')
        git_head = $gitHead
        source = [ordered]@{ host = $SourceHost; port = $SourcePort; database = $SourceDatabase }
        target = [ordered]@{ database = $TargetDatabase }
        safety_backup = $SafetyBackup
        log_file = $LogFile
        error = $_.Exception.Message
    }
    $failed | ConvertTo-Json -Depth 6 | Set-Content -Path $ManifestFile -Encoding UTF8

    Write-Host ""
    Write-Host "FOODEX LEGACY ONE-SHOT STOPPED SAFELY" -ForegroundColor Red
    Write-Host $_.Exception.Message -ForegroundColor Red
    Write-Host "Safety backup: $SafetyBackup" -ForegroundColor Yellow
    Write-Host "Log: $LogFile" -ForegroundColor Yellow
    Write-Host "Manifest: $ManifestFile" -ForegroundColor Yellow
    throw
}
finally {
    try { Stop-Transcript | Out-Null } catch {}
}
param(
    [string]$SourceHost = '172.30.88.33',
    [int]$SourcePort = 1433,
    [string]$SourceDatabase = 'Foodex',
    [string]$TargetDatabase = 'foodex',
    [string]$MySql = 'C:\xampp\mysql\bin\mysql.exe',
    [string]$MySqlDump = 'C:\xampp\mysql\bin\mysqldump.exe',
    [string]$LogDir = 'C:\Live\db-snapshots'
)

$ErrorActionPreference = 'Stop'
$RepoRoot = Split-Path -Parent $PSScriptRoot
$Backend = Join-Path $RepoRoot 'backend'
$Common = Join-Path $PSScriptRoot 'legacy\LegacyPipeline.Common.ps1'
$OneShot = Join-Path $PSScriptRoot 'run-legacy-one-shot.ps1'
$NativeRegistryFile = Join-Path $PSScriptRoot 'legacy\native-phases.json'

if (-not (Test-Path $Common)) { throw "Missing $Common" }
if (-not (Test-Path $OneShot)) { throw "Missing $OneShot" }
if (-not (Test-Path $NativeRegistryFile)) { throw "Missing $NativeRegistryFile" }
. $Common

$NativeRegistry = Get-Content -Raw $NativeRegistryFile | ConvertFrom-Json
$NativeRegistryHash = (Get-FileHash -Algorithm SHA256 $NativeRegistryFile).Hash.ToLowerInvariant()
$ExpectedHead = (git -C $RepoRoot rev-parse HEAD).Trim()
$ExpectedPhases = @($NativeRegistry.phases | Where-Object { $_.enabled } | ForEach-Object { [string]$_.id })
$StartedAt = Get-Date

New-Item -ItemType Directory -Force $LogDir | Out-Null
$stamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$AcceptanceLog = Join-Path $LogDir ("LEGACY_ONE_SHOT_ACCEPTANCE_" + $stamp + '.log')
$AcceptanceManifest = Join-Path $LogDir ("LEGACY_ONE_SHOT_ACCEPTANCE_" + $stamp + '.json')
Start-Transcript -Path $AcceptanceLog | Out-Null

function Assert-JsonCommand {
    param(
        [Parameter(Mandatory=$true)][string]$Name,
        [Parameter(Mandatory=$true)][string[]]$Arguments,
        [Parameter(Mandatory=$true)][scriptblock]$Assert
    )

    Write-Host ""
    Write-Host "==================================================" -ForegroundColor Cyan
    Write-Host $Name -ForegroundColor Cyan
    Write-Host "==================================================" -ForegroundColor Cyan

    $raw = & php artisan @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "$Name FAILED (exit=$LASTEXITCODE)"
    }

    $jsonText = ($raw | Out-String).Trim()
    if ([string]::IsNullOrWhiteSpace($jsonText)) {
        throw "$Name returned empty JSON output."
    }

    try {
        $json = $jsonText | ConvertFrom-Json
    } catch {
        Write-Host $jsonText
        throw "$Name did not return valid JSON: $($_.Exception.Message)"
    }

    & $Assert $json
    Write-Host "PASSED: $Name" -ForegroundColor Green
}

try {
    Set-Location $RepoRoot

    Write-Host "Starting full legacy one-shot acceptance" -ForegroundColor Yellow
    Write-Host "Expected HEAD: $ExpectedHead" -ForegroundColor Yellow
    Write-Host "Registry version: $($NativeRegistry.version)" -ForegroundColor Yellow
    Write-Host "Registry SHA256: $NativeRegistryHash" -ForegroundColor Yellow

    & powershell.exe `
        -NoProfile `
        -ExecutionPolicy Bypass `
        -File $OneShot `
        -SourceHost $SourceHost `
        -SourcePort $SourcePort `
        -SourceDatabase $SourceDatabase `
        -TargetDatabase $TargetDatabase `
        -MySql $MySql `
        -MySqlDump $MySqlDump `
        -LogDir $LogDir

    if ($LASTEXITCODE -ne 0) {
        throw "FULL ONE-SHOT FAILED (exit=$LASTEXITCODE)"
    }

    $oneShotManifestFile = Get-ChildItem $LogDir -Filter 'LEGACY_ONE_SHOT_*.json' -File |
        Where-Object { $_.Name -notlike 'LEGACY_ONE_SHOT_ACCEPTANCE_*' -and $_.LastWriteTime -ge $StartedAt.AddMinutes(-1) } |
        Sort-Object LastWriteTime -Descending |
        Select-Object -First 1

    if ($null -eq $oneShotManifestFile) {
        throw 'One-shot exited successfully but no fresh one-shot manifest was produced.'
    }

    $oneShotManifest = Get-Content -Raw $oneShotManifestFile.FullName | ConvertFrom-Json

    if ([string]$oneShotManifest.status -ne 'passed') {
        throw "One-shot manifest status is not passed: $($oneShotManifest.status)"
    }
    if ([int]$oneShotManifest.acceptance_contract_version -ne 1) {
        throw "Unexpected one-shot acceptance contract version: $($oneShotManifest.acceptance_contract_version)"
    }
    if ([string]$oneShotManifest.git_head -ne $ExpectedHead) {
        throw "One-shot HEAD mismatch. Expected=$ExpectedHead Actual=$($oneShotManifest.git_head)"
    }
    if ([int]$oneShotManifest.native_registry_version -ne [int]$NativeRegistry.version) {
        throw "Registry version mismatch. Expected=$($NativeRegistry.version) Actual=$($oneShotManifest.native_registry_version)"
    }
    if ([string]$oneShotManifest.native_registry_sha256 -ne $NativeRegistryHash) {
        throw "Registry hash mismatch. Expected=$NativeRegistryHash Actual=$($oneShotManifest.native_registry_sha256)"
    }

    $actualPhases = @($oneShotManifest.native_phases | ForEach-Object { [string]$_ })
    if (($ExpectedPhases -join '|') -ne ($actualPhases -join '|')) {
        throw "Native phase list mismatch. Expected=$($ExpectedPhases -join ',') Actual=$($actualPhases -join ',')"
    }

    Set-Location $Backend

    Invoke-FoodexStep 'ACCEPTANCE full archive verify' { php artisan foodex:legacy-archive-verify }
    Invoke-FoodexStep 'ACCEPTANCE stock structural verify' { php artisan foodex:legacy-stock-verify }
    Invoke-FoodexStep 'ACCEPTANCE stock source-to-target reconcile' { php artisan foodex:legacy-stock-reconcile }
    Invoke-FoodexStep 'ACCEPTANCE sales verify' { php artisan foodex:legacy-sales-history-verify }
    Invoke-FoodexStep 'ACCEPTANCE purchase verify' { php artisan foodex:legacy-purchase-history-verify }
    Invoke-FoodexStep 'ACCEPTANCE operational verify' { php artisan foodex:legacy-operational-history-verify }
    Invoke-FoodexStep 'ACCEPTANCE finance-history verify' { php artisan foodex:legacy-finance-history-verify }
    Invoke-FoodexStep 'ACCEPTANCE invoice extras verify' { php artisan foodex:legacy-invoice-extras-verify }

    foreach ($phase in $NativeRegistry.phases | Where-Object { $_.enabled }) {
        Invoke-FoodexStep ("ACCEPTANCE native phase verify: " + $phase.name) {
            $artisanArgs = @($phase.verify)
            php artisan @artisanArgs
        }
    }

    Assert-JsonCommand -Name 'ACCEPTANCE business-impact JSON gate' -Arguments @('foodex:legacy-business-verify','--json') -Assert {
        param($json)
        if (-not [bool]$json.healthy) { throw 'Business-impact JSON gate is unhealthy.' }
        if (@($json.business_failures).Count -ne 0) { throw 'Business-impact failures are not zero.' }
        if (@($json.materialization_failures).Count -ne 0) { throw 'Business materialization failures are not zero.' }
    }

    Assert-JsonCommand -Name 'ACCEPTANCE canonical coverage JSON gate' -Arguments @('foodex:legacy-canonical-coverage','--json') -Assert {
        param($json)
        if (-not [bool]$json.healthy_archive) { throw 'Canonical coverage archive health is false.' }
        if ([int]$json.summary.archive_only_tables -ne 0) { throw "Archive-only nonempty tables remain: $($json.summary.archive_only_tables)" }
        if ([int]$json.summary.archive_only_rows -ne 0) { throw "Archive-only rows remain: $($json.summary.archive_only_rows)" }
    }

    $AcceptedAt = Get-Date
    $acceptance = [ordered]@{
        status = 'passed'
        acceptance_contract_version = 1
        started_at = $StartedAt.ToString('o')
        accepted_at = $AcceptedAt.ToString('o')
        git_head = $ExpectedHead
        native_registry_version = [int]$NativeRegistry.version
        native_registry_sha256 = $NativeRegistryHash
        native_phases = $ExpectedPhases
        one_shot_manifest = $oneShotManifestFile.FullName
        one_shot_log = [string]$oneShotManifest.log_file
        safety_backup = [string]$oneShotManifest.safety_backup
        acceptance_log = $AcceptanceLog
    }
    $acceptance | ConvertTo-Json -Depth 8 | Set-Content -Path $AcceptanceManifest -Encoding UTF8

    Write-Host ""
    Write-Host "==================================================" -ForegroundColor Green
    Write-Host " FOODEX LEGACY ONE-SHOT ACCEPTANCE PASSED" -ForegroundColor Green
    Write-Host " One-shot manifest: $($oneShotManifestFile.FullName)" -ForegroundColor Green
    Write-Host " Acceptance manifest: $AcceptanceManifest" -ForegroundColor Green
    Write-Host " Acceptance log: $AcceptanceLog" -ForegroundColor Green
    Write-Host "==================================================" -ForegroundColor Green
}
catch {
    $failed = [ordered]@{
        status = 'failed'
        acceptance_contract_version = 1
        started_at = $StartedAt.ToString('o')
        failed_at = (Get-Date).ToString('o')
        git_head = $ExpectedHead
        native_registry_version = [int]$NativeRegistry.version
        native_registry_sha256 = $NativeRegistryHash
        native_phases = $ExpectedPhases
        acceptance_log = $AcceptanceLog
        error = $_.Exception.Message
    }
    $failed | ConvertTo-Json -Depth 8 | Set-Content -Path $AcceptanceManifest -Encoding UTF8

    Write-Host ""
    Write-Host "FOODEX LEGACY ONE-SHOT ACCEPTANCE FAILED" -ForegroundColor Red
    Write-Host $_.Exception.Message -ForegroundColor Red
    Write-Host "Acceptance log: $AcceptanceLog" -ForegroundColor Yellow
    Write-Host "Acceptance manifest: $AcceptanceManifest" -ForegroundColor Yellow
    throw
}
finally {
    try { Stop-Transcript | Out-Null } catch {}
}
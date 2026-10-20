param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Invoke-FoodexStep {
    param(
        [Parameter(Mandatory=$true)][string]$Name,
        [Parameter(Mandatory=$true)][scriptblock]$Command
    )

    Write-Host ""
    Write-Host "==================================================" -ForegroundColor Cyan
    Write-Host $Name -ForegroundColor Cyan
    Write-Host "==================================================" -ForegroundColor Cyan

    & $Command

    if ($LASTEXITCODE -ne 0) {
        throw "$Name FAILED (exit=$LASTEXITCODE)"
    }

    Write-Host "PASSED: $Name" -ForegroundColor Green
}

function Set-FoodexEnvValue {
    param(
        [Parameter(Mandatory=$true)][string]$EnvFile,
        [Parameter(Mandatory=$true)][string]$Key,
        [AllowEmptyString()][string]$Value
    )

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

function Invoke-FoodexMySqlScalar {
    param(
        [Parameter(Mandatory=$true)][string]$MySql,
        [Parameter(Mandatory=$true)][string]$Database,
        [Parameter(Mandatory=$true)][string]$Sql
    )

    $result = & $MySql -h 127.0.0.1 -P 3306 -u root --skip-password --batch --skip-column-names $Database -e $Sql
    if ($LASTEXITCODE -ne 0) {
        throw "MySQL scalar query failed."
    }

    return [string]($result | Select-Object -First 1)
}

function New-FoodexBackup {
    param(
        [Parameter(Mandatory=$true)][string]$DumpExe,
        [Parameter(Mandatory=$true)][string]$Database,
        [Parameter(Mandatory=$true)][string]$LogDir,
        [string]$Prefix = 'foodex_ONE_SHOT_BEFORE'
    )

    New-Item -ItemType Directory -Force $LogDir | Out-Null
    $backup = Join-Path $LogDir ($Prefix + '_' + (Get-Date -Format 'yyyyMMdd_HHmmss') + '.sql')

    $args = @(
        '-h','127.0.0.1','-P','3306','-u','root','--skip-password',
        '--single-transaction','--quick','--routines','--triggers','--events','--hex-blob',
        '--default-character-set=utf8mb4','--no-tablespaces',
        $Database,
        "--result-file=$backup"
    )
    & $DumpExe @args

    if ($LASTEXITCODE -ne 0) {
        throw "Safety backup failed."
    }

    if (-not (Test-Path $backup)) {
        throw "Safety backup was not created: $backup"
    }

    return $backup
}
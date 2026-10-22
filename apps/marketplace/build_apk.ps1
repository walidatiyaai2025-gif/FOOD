$ErrorActionPreference = "Stop"

$project = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $project

if (-not (Get-Command flutter -ErrorAction SilentlyContinue)) {
    throw "Flutter is not installed or not available in PATH."
}

Write-Host "[1/5] Preserving standalone Foodx mockup source..." -ForegroundColor Cyan
$backup = Join-Path $env:TEMP ("foodex_mockup_backup_" + [guid]::NewGuid().ToString("N"))
New-Item -ItemType Directory -Path $backup | Out-Null
Copy-Item -Recurse -Force (Join-Path $project "lib") $backup
Copy-Item -Force (Join-Path $project "pubspec.yaml") $backup
Copy-Item -Force (Join-Path $project "analysis_options.yaml") $backup

try {
    Write-Host "[2/5] Creating Android platform shell..." -ForegroundColor Cyan
    flutter create --platforms=android --org com.foodex.mockup --project-name foodex_marketplace_mockup .

    Remove-Item -Recurse -Force (Join-Path $project "lib")
    Copy-Item -Recurse -Force (Join-Path $backup "lib") $project
    Copy-Item -Force (Join-Path $backup "pubspec.yaml") $project
    Copy-Item -Force (Join-Path $backup "analysis_options.yaml") $project

    $manifest = Join-Path $project "android\app\src\main\AndroidManifest.xml"
    $xml = Get-Content $manifest -Raw
    if ($xml -notmatch 'android.permission.INTERNET') {
        $xml = $xml -replace '<manifest xmlns:android="http://schemas.android.com/apk/res/android">', '<manifest xmlns:android="http://schemas.android.com/apk/res/android">`r`n    <uses-permission android:name="android.permission.INTERNET" />'
        Set-Content -Path $manifest -Value $xml -Encoding UTF8
    }

    Write-Host "[3/5] Resolving packages..." -ForegroundColor Cyan
    flutter pub get

    Write-Host "[4/5] Static analysis..." -ForegroundColor Cyan
    flutter analyze

    Write-Host "[5/5] Building release APK..." -ForegroundColor Cyan
    flutter build apk --release

    $sourceApk = Join-Path $project "build\app\outputs\flutter-apk\app-release.apk"
    $targetApk = Join-Path $project "Foodx-Marketplace-Mockup.apk"
    Copy-Item -Force $sourceApk $targetApk
    Write-Host "APK: $targetApk" -ForegroundColor Green
}
finally {
    if (Test-Path $backup) { Remove-Item -Recurse -Force $backup }
}

#!/usr/bin/env bash
set -euo pipefail

test -f VERSION
test -f docs/api/openapi.yaml
test -f docs/installer/INSTALLER_ARCHITECTURE.md
test -f docs/updater/UPDATE_SYSTEM.md
test -f backend/docs/APP_VERSION_POLICY.md
test -f docs/architecture/SECURITY_BASELINE.md
test -f docs/release/RELEASE_CHECKLIST.md
test -f docs/release/RELEASE_NOTES.md
test -f docs/release/PRODUCTION_CONFIGURATION.md
test -f backend/.env.production.example

test -f backend/app/Http/Controllers/Api/V1/HealthController.php
grep -q "Route::get('/health'" backend/routes/api.php
grep -Eq '^  /(?:v1/)?app-version:' docs/api/openapi.yaml || grep -Eq '^  /app-version:' docs/api/openapi.yaml
grep -q 'Clean install' docs/release/RELEASE_CHECKLIST.md
grep -q 'Rollback' docs/release/RELEASE_CHECKLIST.md
grep -q 'Android' docs/release/RELEASE_CHECKLIST.md
grep -q 'iOS' docs/release/RELEASE_CHECKLIST.md

release_version="$(tr -d '\r\n' < VERSION)"
test "$release_version" = "1.0.0"

customer_version="$(awk '/^version:/ {print $2; exit}' apps/customer_app/pubspec.yaml)"
driver_version="$(awk '/^version:/ {print $2; exit}' apps/driver_app/pubspec.yaml)"
test "$customer_version" = "$release_version+1"
test "$driver_version" = "$release_version+1"

grep -Fq "# FOODEX $release_version Release Notes" docs/release/RELEASE_NOTES.md
grep -Fq "## $release_version - Release Candidate" CHANGELOG.md
grep -Fq "VERSION and release notes identify the exact release being promoted. Evidence: #178" docs/release/RELEASE_CHECKLIST.md

production_origin="https://foodex.50sols.com"
grep -Fq "APP_URL=$production_origin" backend/.env.production.example
grep -Fq "DB_DATABASE=solscool_foodex" backend/.env.production.example
grep -Fq "DB_USERNAME=solscool_foodex" backend/.env.production.example
grep -Fq "defaultValue: '$production_origin'" apps/customer_app/lib/core/config/foodex_environment.dart
grep -Fq "defaultValue: '$production_origin'" apps/driver_app/lib/core/config/foodex_environment.dart
! grep -R -Fq "foodex-validation.invalid" .github/workflows/customer-app-ci.yml .github/workflows/driver-app-ci.yml

echo "Release identity $release_version is synchronized across repository and mobile artifacts."
echo 'Release readiness evidence is structurally complete.'

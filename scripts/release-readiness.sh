#!/usr/bin/env bash
set -euo pipefail

test -f VERSION
test -f docs/api/openapi.yaml
test -f docs/installer/INSTALLER_ARCHITECTURE.md
test -f docs/updater/UPDATE_SYSTEM.md
test -f backend/docs/APP_VERSION_POLICY.md
test -f docs/architecture/SECURITY_BASELINE.md
test -f docs/release/RELEASE_CHECKLIST.md

grep -Eq '^  /(?:v1/)?health:' docs/api/openapi.yaml || grep -Eq '^  /health:' docs/api/openapi.yaml
grep -Eq '^  /(?:v1/)?app-version:' docs/api/openapi.yaml || grep -Eq '^  /app-version:' docs/api/openapi.yaml
grep -q 'Clean install' docs/release/RELEASE_CHECKLIST.md
grep -q 'Rollback' docs/release/RELEASE_CHECKLIST.md
grep -q 'Android' docs/release/RELEASE_CHECKLIST.md
grep -q 'iOS' docs/release/RELEASE_CHECKLIST.md

echo 'Release readiness evidence is structurally complete.'

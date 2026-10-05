#!/usr/bin/env bash
set -euo pipefail

base="${1:-}"
head="${2:-HEAD}"
output="${3:-}"

if [[ -z "$base" ]]; then
  if git rev-parse --verify origin/main >/dev/null 2>&1; then
    base="$(git merge-base origin/main "$head")"
  else
    base="$(git rev-parse "$head^" 2>/dev/null || git rev-parse "$head")"
  fi
fi

tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT
if [[ "$head" == "WORKTREE" ]]; then
  git diff --name-only "$base" > "$tmp"
  git ls-files --others --exclude-standard >> "$tmp"
  sort -u -o "$tmp" "$tmp"
else
  git diff --name-only "$base" "$head" > "$tmp"
fi

backend=false
customer=false
driver=false
app_preview_visual=false
preview_runtime_distribution=false
policy=false

match() {
  grep -Eq "$1" "$tmp"
}

match '^(backend/|docs/api/openapi\.yaml$|\.github/workflows/backend-ci\.yml$)' && backend=true
match '^(apps/customer_app/|packages/design_tokens/|scripts/package-mobile-artifact\.py$|scripts/tests/test_mobile_artifact\.py$|\.github/workflows/customer-app-ci\.yml$)' && customer=true
match '^(apps/driver_app/|packages/design_tokens/|scripts/package-mobile-artifact\.py$|scripts/tests/test_mobile_artifact\.py$|\.github/workflows/driver-app-ci\.yml$)' && driver=true
match '^(AGENTS\.md|docs/worker-rules/|scripts/(worker-preflight|detect-changed-areas)\.sh|\.github/workflows/(repository-policy|required-ci-gate)\.yml)$' && policy=true

if match '^(\.github/workflows/required-ci-gate\.yml|\.github/workflows/release-validation\.yml|\.github/workflows/app-preview-acceptance-ci\.yml|scripts/release-readiness\.sh|docs/release/|docs/quality/APP_PREVIEW_FINAL_ACCEPTANCE\.md$)'; then
  backend=true
  customer=true
  driver=true
fi

match '^(apps/(customer_app|driver_app)/(lib/core/preview/|lib/preview_main\.dart|test/(customer|driver)_preview)|backend/(config/app_preview\.php|resources/views/admin/app-preview\.blade\.php)|scripts/app_preview_visual_parity\.mjs|docs/quality/APP_PREVIEW_VISUAL_PARITY\.md|\.github/workflows/app-preview-(acceptance-ci|visual-parity)\.yml|\.github/workflows/required-ci-gate\.yml)' && app_preview_visual=true

match '^(VERSION$|apps/(customer_app|driver_app)/(pubspec\.yaml|lib/preview_main\.dart|lib/core/preview/|web/)|backend/\.env\.production\.example$|scripts/package-preview-runtimes\.py$|scripts/tests/test_preview_runtime_distribution\.py$|docs/release/PREVIEW_RUNTIME_DISTRIBUTION\.md$|\.github/workflows/preview-runtime-distribution\.yml$)' && preview_runtime_distribution=true

emit() {
  echo "backend=$backend"
  echo "customer=$customer"
  echo "driver=$driver"
  echo "app_preview_visual=$app_preview_visual"
  echo "preview_runtime_distribution=$preview_runtime_distribution"
  echo "policy=$policy"
}

if [[ -n "$output" ]]; then
  emit >> "$output"
else
  emit
fi

#!/usr/bin/env bash
set -euo pipefail
required=(README.md VERSION CHANGELOG.md backend/composer.json apps/customer_app/pubspec.yaml apps/driver_app/pubspec.yaml docs/api/openapi.yaml docs/design-reference/INDEX.md docs/worker-rules/WORKER_GOVERNANCE.md)
for f in "${required[@]}"; do test -f "$f" || { echo "Missing required file: $f"; exit 1; }; done
branch="${GITHUB_HEAD_REF:-${GITHUB_REF_NAME:-}}"
if [[ -n "$branch" && "$branch" != "main" ]]; then
  [[ "$branch" =~ ^(feat|fix|chore|docs|refactor|test|release|ci)/[0-9]+-[a-z0-9-]+$ ]] || { echo "Invalid issue branch name: $branch"; exit 1; }
fi
echo "Repository foundation policy check passed."

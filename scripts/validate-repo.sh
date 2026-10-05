#!/usr/bin/env bash
set -euo pipefail
required=(README.md VERSION CHANGELOG.md backend/composer.json apps/customer_app/pubspec.yaml apps/driver_app/pubspec.yaml docs/api/openapi.yaml docs/design-reference/INDEX.md docs/worker-rules/WORKER_GOVERNANCE.md docs/release/RELEASE_REGISTRY.json .github/scripts/release-registry.js)
for f in "${required[@]}"; do test -f "$f" || { echo "Missing required file: $f"; exit 1; }; done
branch="${GITHUB_HEAD_REF:-${GITHUB_REF_NAME:-}}"
if [[ -n "$branch" && "$branch" != "main" && "$branch" != "feat/assistant-v1-integration" && "$branch" != "feat/catalog-products-ui-redesign" ]]; then
  [[ "$branch" =~ ^((feat|fix|chore|docs|refactor|test|ci)/[0-9]+-[a-z0-9-]+|release/([0-9]+|[0-9]+\.[0-9]+\.[0-9]+)-[a-z0-9-]+)$ ]] || { echo "Invalid issue branch name: $branch"; exit 1; }
fi
node .github/scripts/release-registry.js validate
node --test .github/scripts/release-registry.test.js
echo "Repository foundation policy check passed."

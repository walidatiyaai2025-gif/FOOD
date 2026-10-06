#!/usr/bin/env bash
set -euo pipefail
required=(README.md VERSION CHANGELOG.md backend/composer.json apps/customer_app/pubspec.yaml apps/driver_app/pubspec.yaml docs/api/openapi.yaml docs/design-reference/INDEX.md docs/worker-rules/WORKER_GOVERNANCE.md docs/release/RELEASE_REGISTRY.json .github/scripts/release-registry.js docs/execution/ACTIVE_FOOD_MISSION.json docs/execution/UIUX_V42_AUTONOMOUS_MISSION_PLAN.md scripts/validate-active-mission.py)
for f in "${required[@]}"; do test -f "$f" || { echo "Missing required file: $f"; exit 1; }; done
branch="${GITHUB_HEAD_REF:-${GITHUB_REF_NAME:-}}"
if [[ -n "$branch" && "$branch" != "main" && "$branch" != "feat/assistant-v1-integration" ]]; then
  [[ "$branch" =~ ^((feat|fix|chore|docs|refactor|test|ci)/[0-9]+-[a-z0-9-]+|release/([0-9]+|[0-9]+\.[0-9]+\.[0-9]+)-[a-z0-9-]+)$ ]] || { echo "Invalid issue branch name: $branch"; exit 1; }
fi
node .github/scripts/release-registry.js validate
node --test .github/scripts/release-registry.test.js
python3 scripts/validate-active-mission.py validate
python3 -m unittest discover -s scripts/tests -p 'test_active_mission_registry.py'
echo "Repository foundation policy check passed."

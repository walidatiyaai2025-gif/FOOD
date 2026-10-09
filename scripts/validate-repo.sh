#!/usr/bin/env bash
set -euo pipefail
required=(README.md VERSION CHANGELOG.md backend/composer.json apps/customer_app/pubspec.yaml apps/driver_app/pubspec.yaml docs/api/openapi.yaml docs/design-reference/INDEX.md docs/worker-rules/WORKER_GOVERNANCE.md docs/release/RELEASE_REGISTRY.json .github/scripts/release-registry.js docs/execution/ACTIVE_FOOD_MISSION.json docs/execution/UIUX_V42_AUTONOMOUS_MISSION_PLAN.md scripts/validate-active-mission.py docs/execution/UI_ROUTE_AUTHORITY.json scripts/validate_ui_route_convergence.py)
for f in "${required[@]}"; do test -f "$f" || { echo "Missing required file: $f"; exit 1; }; done
branch="${GITHUB_HEAD_REF:-${GITHUB_REF_NAME:-}}"
if [[ -n "$branch" && "$branch" != "main" && "$branch" != "feat/assistant-v1-integration" && "$branch" != "chore/geography-reset-one-shot" ]]; then
  [[ "$branch" =~ ^((feat|fix|chore|docs|refactor|test|ci)/[0-9]+-[a-z0-9-]+|release/([0-9]+|[0-9]+\.[0-9]+\.[0-9]+)-[a-z0-9-]+)$ ]] || { echo "Invalid issue branch name: $branch"; exit 1; }
fi
node .github/scripts/release-registry.js validate
node --test .github/scripts/release-registry.test.js
python3 scripts/validate-active-mission.py validate
python3 -m unittest discover -s scripts/tests -p 'test_active_mission_registry.py'
python3 scripts/validate_ui_route_convergence.py validate
python3 -m unittest discover -s scripts/tests -p 'test_ui_route_convergence_registry.py'
python3 - <<'PY'
from pathlib import Path

workflow = Path(".github/workflows/trial-distribution.yml").read_text(encoding="utf-8")
forbidden = "EFFECTIVE_FROM=\"$(date -u '+%Y-%m-%dT%H:%M:%S+00:00')\""
if forbidden in workflow:
    raise SystemExit(
        "Release policy violation: active/effective fresh-install fixtures must not use the exact current-time boundary. "
        "Use a deterministic past safety margin."
    )

territory_create = workflow.find("/tmp/territory-post.html")
assignment_create = workflow.find("/tmp/assignment-post.html")
if territory_create < 0 or assignment_create < 0 or territory_create > assignment_create:
    raise SystemExit(
        "Release policy violation: fresh-install dependencies must be created before consumers "
        "(Service Territory must exist before the Van Assignment references it)."
    )

side_effect_assertion = "Van assignment POST redirected but did not persist the expected FRESH-VAN-UI / FRESH-TERR assignment."
if side_effect_assertion not in workflow:
    raise SystemExit(
        "Release policy violation: HTTP 302 alone is not mutation evidence; "
        "the fresh-install Van Assignment POST must verify its persisted side effect."
    )
PY
echo "Repository foundation policy check passed."

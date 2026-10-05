#!/usr/bin/env bash
set -euo pipefail

mode="fast"
case "${1:-}" in
  ""|"--fast") mode="fast" ;;
  "--full") mode="full" ;;
  *) echo "Usage: $0 [--fast|--full]" >&2; exit 2 ;;
esac

root="$(git rev-parse --show-toplevel)"
cd "$root"

branch="$(git branch --show-current)"
if [[ -n "$branch" && "$branch" != "main" && "$branch" != "feat/assistant-v1-integration" ]]; then
  [[ "$branch" =~ ^((feat|fix|chore|docs|refactor|test|ci)/[0-9]+-[a-z0-9-]+|release/([0-9]+|[0-9]+\.[0-9]+\.[0-9]+)-[a-z0-9-]+)$ ]] || {
    echo "Invalid issue-style branch: $branch" >&2
    exit 1
  }
fi

base=""
if git rev-parse --verify origin/main >/dev/null 2>&1; then
  base="$(git merge-base origin/main HEAD)"
else
  base="$(git rev-parse HEAD^ 2>/dev/null || git rev-parse HEAD)"
fi

echo "FOODEX preflight mode=$mode branch=$branch base=$base head=$(git rev-parse HEAD)"
git diff --check "$base" HEAD
./scripts/validate-repo.sh

declare -A area
while IFS='=' read -r key value; do
  area["$key"]="$value"
done < <(bash ./scripts/detect-changed-areas.sh "$base" HEAD)

changed_php=()
while IFS= read -r path; do
  [[ "$path" == *.php ]] && changed_php+=("$path")
done < <(git diff --name-only "$base" HEAD)

if [[ "${area[backend]:-false}" == "true" ]]; then
  command -v php >/dev/null || { echo "PHP is required for backend preflight." >&2; exit 1; }
  command -v composer >/dev/null || { echo "Composer is required for backend preflight." >&2; exit 1; }

  for path in "${changed_php[@]}"; do
    [[ -f "$path" ]] && php -l "$path" >/dev/null
  done

  (
    cd backend
    composer validate --strict --no-check-lock
    [[ -d vendor ]] || composer install --no-interaction --prefer-dist --no-progress
    composer lint
    composer analyse

    if [[ "$mode" == "full" ]]; then
      cp .env.example .env
      php artisan key:generate
      mkdir -p database
      touch database/database.sqlite
      DB_CONNECTION=sqlite DB_DATABASE=database/database.sqlite php artisan migrate:fresh --force
      DB_CONNECTION=sqlite DB_DATABASE=database/database.sqlite php artisan db:seed --force
      composer test
      composer audit
    fi
  )
fi

run_flutter() {
  local app="$1"
  local dir="apps/${app}_app"
  command -v flutter >/dev/null || { echo "Flutter is required for $app preflight." >&2; exit 1; }
  (
    cd "$dir"
    flutter pub get
    flutter analyze

    mapfile -t tests < <(find test -type f -name '*_test.dart' ! -name 'screenshot_evidence_test.dart' ! -name 'top_products_visual_trial_test.dart' -print 2>/dev/null || true)
    if (("${#tests[@]}" > 0)); then
      flutter test "${tests[@]}"
    fi

    if [[ "$mode" == "full" ]]; then
      flutter build apk --debug --dart-define=FOODEX_API_BASE_URL=https://foodex.50sols.com
    fi
  )
}

[[ "${area[customer]:-false}" == "true" ]] && run_flutter customer
[[ "${area[driver]:-false}" == "true" ]] && run_flutter driver

echo "FOODEX $mode preflight passed for all affected areas."

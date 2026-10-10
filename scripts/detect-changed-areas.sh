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

args=(--base "$base" --head "$head" --branch "${GITHUB_HEAD_REF:-$(git branch --show-current)}")
if [[ -n "$output" ]]; then
  python3 ./scripts/ci-plan.py "${args[@]}" >> "$output"
else
  python3 ./scripts/ci-plan.py "${args[@]}"
fi

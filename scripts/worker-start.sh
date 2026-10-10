#!/usr/bin/env bash
set -euo pipefail

root="$(git rev-parse --show-toplevel)"
cd "$root"

if [[ "${1:-}" == "--new" ]]; then
  [[ "$#" -eq 4 ]] || { echo "Usage: $0 --new <issue> <kind> <slug>" >&2; exit 2; }
  issue="$2"
  kind="$3"
  slug="$4"
  current="$(git branch --show-current)"
  [[ "$current" == "main" ]] || { echo "New task branches must be created from main; current=$current" >&2; exit 1; }
  [[ -z "$(git status --porcelain)" ]] || { echo "Working tree must be clean before creating a task branch." >&2; exit 1; }
  git fetch origin main
  new_branch="$(python3 scripts/foodex-branch-policy.py name "$issue" "$kind" "$slug")"
  git switch -c "$new_branch" origin/main
fi

branch="$(git branch --show-current)"
if [[ "$branch" == "main" ]]; then
  echo "Refusing implementation work directly on main." >&2
  echo "Start with: bash scripts/worker-start.sh --new <issue> <kind> <slug>" >&2
  exit 1
fi
python3 scripts/foodex-branch-policy.py validate "$branch"

head="$(git rev-parse HEAD)"
base_ref="origin/main"
if ! git rev-parse --verify "$base_ref" >/dev/null 2>&1; then
  base_ref="main"
fi
base="$(git merge-base "$base_ref" HEAD)"

echo "FOODEX worker bootstrap"
echo
echo "branch=$branch"
echo "head=$head"
echo "base_ref=$base_ref"
echo "base=$base"

echo
echo "Resolved CI plan:"
python3 scripts/ci-plan.py --base "$base" --head WORKTREE --branch "$branch"

echo
echo "Resolved skill pack:"
python3 scripts/foodex-skill-router.py --base "$base" --head WORKTREE --strict || true

echo
echo "Recent commits:"
git log --oneline --decorate -8

echo
echo "Baseline diff summary:"
git diff --stat "$base"

echo
echo "Required command before first push:"
echo "  bash ./scripts/worker-preflight.sh --ci-parity"

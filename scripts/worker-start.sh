#!/usr/bin/env bash
set -euo pipefail

root="$(git rev-parse --show-toplevel)"
cd "$root"

branch="$(git branch --show-current)"
head="$(git rev-parse HEAD)"
base_ref="origin/main"

if ! git rev-parse --verify "$base_ref" >/dev/null 2>&1; then
  base_ref="main"
fi

base="$(git merge-base "$base_ref" HEAD)"

echo "FOODEX worker bootstrap"
echo "branch=$branch"
echo "head=$head"
echo "base_ref=$base_ref"
echo "base=$base"

git status --short

echo
echo "Affected areas:"
bash ./scripts/detect-changed-areas.sh "$base" WORKTREE

echo
echo "Recent commits:"
git log --oneline --decorate -8

echo
echo "Baseline diff summary:"
git diff --stat "$base"

echo
echo "Next required command before push:"
echo "  bash ./scripts/worker-preflight.sh --fast"

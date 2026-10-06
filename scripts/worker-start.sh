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
echo
echo "MANDATORY UI/UX CONTRACT:"
echo "  docs/design-reference/DASHBOARD_UI_UX_CONTRACT.md"
echo "  Read before changing Dashboard/Admin/business-facing UI."
echo "  No raw IDs/keys/codes/JSON when a Lookup/Enum/Builder is appropriate."
echo "  Customer + Driver + Van application parity must be evaluated."
echo "  Mobile UI: compact header, full viewport, one-line filters, no wrapped order numbers, compact rows, green ellipsis actions."
echo "  Auth parity: Remember Me + biometric unlock for Customer/Driver/Van; never store plaintext passwords."
echo "  Dashboard map: unified Live Tracking for Drivers (person icon) + Vans (vehicle icon)."
echo
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

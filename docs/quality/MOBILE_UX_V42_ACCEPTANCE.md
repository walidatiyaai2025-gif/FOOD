# FOODEX Mobile UX v4.2 Acceptance Guard

Issue: #1002  
Parent Mission: #1001 — UIUX-V42  
Authoritative product contract: `docs/design-reference/DASHBOARD_UI_UX_CONTRACT.md` §14.

## Purpose

`scripts/mobile-ux-contract-guard.py` is a cheap deterministic anti-regression gate for
Customer, Driver and Van mobile UI changes. It is intentionally source/diff based so
workers get feedback before expensive Flutter builds or screenshot/runtime QA.

It does **not** fabricate visual evidence and it does not replace real runtime review.
The approximate 1% title/subtitle target, perceived density, Arabic/English visual
balance and touch usability still require the normal widget/runtime/visual acceptance
owned by the implementation lanes and later mission gates.

## What the guard enforces

The same rule set is applied to all three roots:

- `apps/customer_app/lib`
- `apps/driver_app/lib`
- `apps/van_app/lib`

For newly added/changed Dart source it rejects deterministic high-signal regressions:

| Contract | Cheap source guard |
|---|---|
| §14.1 Compact title/header footprint | rejects newly introduced `toolbarHeight > 72`, `expandedHeight > 120`, and oversized header `SizedBox` patterns |
| §14.2 Full available screen | rejects a newly introduced narrow centered `Scaffold` body using `ConstrainedBox(maxWidth <= 520)` |
| §14.3 One-line filters | rejects newly introduced Start/End filter controls stacked in a `Column` without a same-line/horizontal layout |
| §14.4 Order number never wraps | requires Text widgets rendering order/assignment identifiers to declare `maxLines: 1`, `softWrap: false`, or controlled `TextOverflow` |
| §14.6 Ellipsis row actions | rejects new mobile list rows that add multiple inline action buttons without a popup/ellipsis action pattern |

The repository self-check also fails if Customer, Driver or Van disappears from guard
coverage or if the authoritative §14 contract clauses are removed.

## Why the guard is diff-based

Existing screens are not rewritten merely to make a static checker green. The guard
blocks newly introduced regressions while the dedicated #1003-#1005 implementation
lanes bring existing Customer/Driver/Van screens into the final v4.2 contract.

That keeps #1002 inside its ownership fence: acceptance/lint/test infrastructure only.

## Local/preflight use

Against the worktree:

```bash
base="$(git merge-base origin/main HEAD)"
python3 scripts/mobile-ux-contract-guard.py --base "$base" --head WORKTREE
```

Against two committed refs:

```bash
python3 scripts/mobile-ux-contract-guard.py --base <base-sha> --head <head-sha>
```

Unit tests:

```bash
python3 -m unittest discover -s scripts/tests -p 'test_mobile_ux_contract_guard.py'
```

`./scripts/worker-preflight.sh --fast` runs the guard automatically. Required CI runs
both the unit tests and the PR diff guard in the always-on Repository Policy job.

## Review expectations beyond the static guard

For every changed Customer/Driver/Van runtime screen, reviewers and the later
UIUX-V42 mission gates still verify the complete §14 contract, including:

- compact title + subtitle footprint;
- efficient full-width/full-height use;
- filter density on narrow devices;
- identifier readability;
- compact list/grid rows;
- one green ellipsis action pattern;
- readable typography;
- Arabic/English and RTL/LTR parity.

A static PASS means no deterministic anti-regression rule fired. It is not a screenshot,
device, or production-runtime PASS.

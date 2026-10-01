---
name: Assistant bug
about: Defect confined to FOOD Assistant behavior
title: "[AI-BUG] "
labels: "worker:ready"
assignees: ""
---

<!-- foodex-worker:managed -->

## Classification

Track: AI-BUG
Production release blocker: **NO**, unless explicitly reclassified by the repository owner.
Assistant integration target: `feat/assistant-v1-integration`

## Reproduction

Describe the Assistant-only failure and confirm whether it reproduces with `ASSISTANT_ENABLED=false`.

## Expected / actual

Expected:

Actual:

## Scope

- Affected Assistant area:
- Current Assistant branch/PR if one already owns the defect:
- Platform files that must not be changed unless a separate platform bug is created:

## Acceptance

- [ ] Assistant defect fixed on the existing owning branch/PR when applicable.
- [ ] Regression test added.
- [ ] No unrelated platform release dependency introduced.

## Release Isolation Contract

If the defect reproduces with Assistant disabled, reclassify/create `[PLATFORM-BUG]` and fix the platform on `main` first.

Do not make normal FOODEX releases wait for this AI-BUG.

## Worker state

<!-- foodex-worker-state:v1 -->
STATE:
OWNER:
BRANCH:
PR:
HEAD:
HEARTBEAT:
BLOCKER: none
NEXT_ACTION:

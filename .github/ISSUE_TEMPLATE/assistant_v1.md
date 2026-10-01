---
name: Assistant V1 implementation task
about: Atomic FOOD Assistant V1 work isolated from normal platform releases
title: "[AI-V1] "
labels: "worker:ready"
assignees: ""
---

<!-- foodex-worker:managed -->

Parent: #<ASSISTANT_UMBRELLA>

## Classification

Track: AI-V1
Production release blocker: **NO**
Base/target integration branch: `feat/assistant-v1-integration`

## Goal

Describe one atomic Assistant implementation unit.

## Scope / ownership fence

- In:
- Out:
- Owned files/subsystem:
- Shared files to avoid while platform work is active:

## Dependencies

List Assistant child Issues/PRs that must merge first.

## Acceptance

- [ ]

## Tests

- [ ]

## Release Isolation Contract

This Issue MUST NOT block FOODEX platform bugs, hotfixes, or normal releases.

If a platform bug needs a shared file, the platform bug merges/releases on `main` first. This Assistant lane later absorbs latest `main` and owns any adaptation/conflict.

Do not modify `VERSION`, Customer/Driver release identity, normal release distribution, or production activation.

Assistant failures stay isolated from normal release gates.

## Worker preflight

Read `AGENTS.md` and `docs/architecture/FOODEX_ASSISTANT_V1_CHARTER.md`. Reuse any existing Issue branch/PR. Claim before editing.

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

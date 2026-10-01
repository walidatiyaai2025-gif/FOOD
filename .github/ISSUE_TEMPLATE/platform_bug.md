---
name: FOODEX platform bug
about: Platform defect independent of Assistant V1
title: "[PLATFORM-BUG] "
labels: "worker:ready"
assignees: ""
---

<!-- foodex-worker:managed -->

## Classification

Track: PLATFORM
Assistant dependency: **NONE by default**

## Reproduction

## Expected / actual

Expected:

Actual:

## Severity / release impact

- Production impact:
- Release blocker: Yes / No
- Hotfix required: Yes / No

## Scope

- Affected module/files:
- Shared Assistant-owned files, if any:

## Acceptance

- [ ]
- [ ] Regression test added.
- [ ] Fix is merged to `main` without waiting for Assistant V1.

## Assistant isolation rule

If this bug touches files also changed by Assistant V1, this platform bug owns the `main` correction first. Assistant workers absorb latest `main` afterwards. Do not wait for Assistant CI/integration.

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

# FOODEX Current State — Orientation Snapshot

> Snapshot captured 2026-10-10. This file is intentionally non-authoritative. Live GitHub state always wins.

## Snapshot

- Repository: `walidatiyaai2025-gif/FOOD`
- Default integration branch: `main`
- Snapshot main SHA: `1fd3e43c878a6bb0faa338265b080be5854767c7`
- Root `VERSION`: `1.0.67`
- Backend: Laravel 12 / PHP 8.2+
- Mobile apps: Customer, Driver, Van (Flutter)

At snapshot time, `docs/execution/ACTIVE_FOOD_MISSION.json` identifies `UIUX-V42-RECOVERY` / Issue #1034 as an active mission registry entry with integration target `release/1034-uiux-v42-recovery`.

That registry explicitly says status comes from live GitHub Issues/PRs/branches/CI. Never assume the mission or a child is active merely because this file says so.

## Before using this snapshot

Reconstruct real state:

1. fetch latest `main` SHA;
2. inspect target Issue and latest comments;
3. inspect exact branch and PR;
4. inspect exact-head CI/checks;
5. inspect active parent mission/dependencies;
6. compare current source/tests to the task request.

If any value differs, use live state.

## What belongs here

Good: current release line as a dated snapshot; current major mission pointer; a major migration; a temporary cross-cutting constraint.

Bad: per-worker heartbeat; unqualified "CI is green"; every open bug; chat-only assumptions; credentials or secrets.

Task execution state belongs in Issue/PR handoffs.

# FOODEX Fixed-Scope Closure Mode

Status: **ACTIVE**  
Owner command: **`CLOSE FOOD`**  
Authority: #1888  
Release boundary: **FOODEX Enterprise ERP GA (#1485)**

## Purpose

This contract creates a real finish line. While active, FOODEX stops expanding the current release and drains only the bounded Enterprise GA closure set.

## In scope

| Order | Authority | Closure responsibility |
|---|---|---|
| 1 | #1883 | Finish W3 module manifest, entitlement and disable/re-enable gate into `feature/enterprise-wms`. |
| 2 | #1551 | Execute and accept genuine representative-heavy and supported cPanel/reference certification. |
| 3 | #1720 / #1489 | Wave 1 certification and promotion. |
| 4 | #1490 | Wave 2 Procurement promotion. |
| 5 | #1491 | Wave 3 WMS promotion. |
| 6 | #1492 | Wave 4 Finance promotion. |
| 7 | #1493 | Wave 5 Treasury promotion, including applicable real pilot/human evidence. |
| 8 | #1494 | Wave 6 Commercial/Distribution promotion. |
| 9 | #1495 | Wave 7 BI/Reporting promotion. |
| 10 | #1497 | Final cross-module certification. |
| 11 | #1485 | Close the Enterprise ERP umbrella after all required evidence is green. |

## Deferred during closure

- #1572 Restaurant SaaS 2.0;
- new product features or roadmap expansion;
- opportunistic refactors/redesigns/optimizations;
- new mobile work unless a mobile-impacting source/shared/API/auth/schema contract changed or final release certification requires it.

## Promotion discipline

Promote W1 -> W2 -> W3 -> W4 -> W5 -> W6 -> W7 sequentially. Before each promotion, synchronize the canonical integration branch with current `main`, rerun exact-head required gates, and consume the genuine cPanel/reference authority where required.

Do not bypass a human gate, pilot gate, real-reference performance gate, or exact-head CI. A blocked gate is a blocker to solve, not permission to create more scope.

## Mainline freeze

During the promotion train, keep `main` quiet. Only P0/P1 release blockers or changes strictly required to make a closure gate pass may interrupt the train.

## Existing non-closure work

An already-running non-closure atomic lane may finish only when it is already WAITING_CI/merge-ready and targets a non-main integration branch. It must not spawn successor work. Otherwise checkpoint, hand off, and park it.

## Definition of done

Closure is complete only when #1551 evidence is accepted, required W1-W7 promotions are on `main`, #1497 passes with zero unresolved P0/P1 blockers, and #1485 is closed. Remaining roadmap work stays deferred to a later release boundary.

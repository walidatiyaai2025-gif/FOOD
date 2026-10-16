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
| Production only | #1551 | Execute genuine representative-heavy cPanel/reference certification only after an explicit owner Production-release command; it does not block W1-W7 closure promotion. |
| 2 | #1720 / #1489 | Wave 1 certification and promotion. |
| 3 | #1490 | Wave 2 Procurement promotion. |
| 4 | #1491 | Wave 3 WMS promotion. |
| 5 | #1492 | Wave 4 Finance promotion. |
| 6 | #1493 | Wave 5 Treasury promotion, including applicable real pilot/human evidence. |
| 7 | #1494 | Wave 6 Commercial/Distribution promotion. |
| 8 | #1495 | Wave 7 BI/Reporting promotion. |
| 9 | #1497 | Final cross-module certification. |
| 10 | #1485 | Close the Enterprise ERP umbrella after all required evidence is green. |

## Deferred during closure

- #1572 Restaurant SaaS 2.0;
- new product features or roadmap expansion;
- opportunistic refactors/redesigns/optimizations;
- new mobile work unless a mobile-impacting source/shared/API/auth/schema contract changed or final release certification requires it.

## Promotion discipline

Promote W1 -> W2 -> W3 -> W4 -> W5 -> W6 -> W7 sequentially. Before each promotion, synchronize the canonical integration branch with current `main` and rerun exact-head repository-required gates. cPanel/reference certification is not a per-wave promotion gate.

Run #1551 only when the owner explicitly requests a Production release. At that point the final production candidate must pass the genuine cPanel/reference contract on the exact production SHA before deployment. Human/pilot gates that belong to a wave remain mandatory and are not changed by this policy.

## Mainline freeze

During the promotion train, keep `main` quiet. Only P0/P1 release blockers or changes strictly required to make a closure gate pass may interrupt the train.

## Existing non-closure work

An already-running non-closure atomic lane may finish only when it is already WAITING_CI/merge-ready and targets a non-main integration branch. It must not spawn successor work. Otherwise checkpoint, hand off, and park it.

## Definition of done

Closure is complete when required W1-W7 promotions are on `main`, #1497 passes with zero unresolved P0/P1 blockers, and #1485 is closed. #1551 is a separate production-only gate and becomes mandatory only when the owner explicitly requests Production deployment. Remaining roadmap work stays deferred to a later release boundary.

# Enterprise N+1 / Query Scaling Gate

Issue: #1507  
Parent: #1488

Critical lists, grids and dashboard collections are measured at two bounded cardinalities. The
performance runner records query counts for the same journey at a smaller and larger row count, then
`QueryScalingGate` evaluates the incremental query slope.

Default gate:
- fixed query increase of up to 2 queries is tolerated;
- after that, more than 0.10 additional queries per added row is FAIL;
- a classic per-row relation/aggregate loop approaches a slope of 1.0 and fails decisively.

The gate is intentionally based on query growth, not wall-clock timing, so shared-host timing noise
cannot hide an N+1 regression. It complements the separate >15% total query-count regression gate.

Certification evidence must record both cardinalities and both query counts. A worker may tighten the
slope for a scenario, but weakening it requires explicit architecture justification in the owning
Issue/PR.

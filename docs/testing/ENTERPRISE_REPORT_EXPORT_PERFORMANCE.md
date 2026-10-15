# Enterprise Report & Export Performance Gate

Issue: #1512  
Parent: #1488

Interactive reports and bulk exports are separate workloads.

Interactive report routes remain bounded by explicit date/store/tenant filters and are measured by the
shared #1503 journey runner. On a cPanel reference environment, the applicable p95 guardrail is
2.5 seconds.

Bulk export behavior is certified independently. The hard boundary is the authoritative Enterprise
contract:

- up to 5,000 rows may use a bounded synchronous path when memory/latency remain acceptable;
- above 5,000 rows, the export must leave the synchronous request path;
- large exports require explicit chunking (or queued chunking according to deployment capability);
- large exports must be resumable after timeout/failure;
- requester/tenant/store authorization remains captured at export creation.

`ReportExportGate` emits a machine-readable PASS/FAIL decision from row count, execution mode,
request-path behavior, chunk size and resumability. The gate intentionally does not invent a fixed
chunk size; module owners must choose a bounded chunk appropriate to their schema and cPanel slice
budget, then provide measured evidence.

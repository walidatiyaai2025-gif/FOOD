# FOODEX 1.0.110 Dashboard Update

## Scope

Dashboard/backend-only release. Customer, Driver and Van app identities are unchanged.

## AI Analytics & Intelligence

- The existing Intelligence Center now exposes a platform-level AI Analytics surface for authorized platform administrators.
- Every displayed result carries explicit provenance: `DATABASE`, `DETERMINISTIC`, `AGENT AI` or `HYBRID`.
- The page shows platform coverage, domain pipeline state, database KPIs, Agent executive opinion, per-domain Agent opinions, analysis trace and run history.
- When the provider does not return a usable answer, FOODEX shows a database-only partial state instead of presenting deterministic facts as Agent opinion.

## Background execution contract

- `foodex:assistant-analysis:coordinate` creates or resumes a durable analysis run.
- `foodex:assistant-analysis:work` claims exactly one bounded chunk and waits for the Agent result before completing that chunk.
- Laravel Scheduler starts the worker in the background every minute, so a slow model does not block `schedule:run`.
- Background Agent calls may use up to 120 seconds while interactive Assistant calls retain their normal latency contract.
- Stale running chunks are returned to pending state for safe retry.
- The default batch is 100 stores per chunk and is configurable with `ASSISTANT_ANALYSIS_STORE_BATCH_SIZE`.

## Safety and provenance

- Database metrics are computed server-side; the model does not receive SQL access.
- Agent instructions prohibit invented numbers, hidden causes or claims of completed actions.
- Prompts and chain-of-thought are not displayed or persisted on the Analytics page.
- Platform analysis visibility and manual execution are restricted to SUPER_ADMIN / platform-management authority.

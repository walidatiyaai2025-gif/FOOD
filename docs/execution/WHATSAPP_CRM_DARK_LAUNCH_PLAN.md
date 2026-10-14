# FOODEX WhatsApp CRM — Non-Blocking Dark Launch Execution Plan

Parent program: #1399

Integration branch: `feature/whatsapp-crm-dark-launch`

## Product rule

Implementation starts immediately. Production use starts only after complete end-to-end certification.

The existence or incompleteness of WhatsApp CRM must never delay an unrelated FOOD release.

## Release isolation

Before GA:

- current and future FOOD release branches are free to ship without merging this integration branch;
- WhatsApp CRM is not a required release-readiness gate;
- no WhatsApp/Meta secret is required for FOOD boot, tests, build, deployment, upgrade or release;
- core order, payment, customer and store flows must behave identically when WhatsApp is disabled or unavailable;
- all database evolution is additive and backward-compatible;
- no existing core table gains a required WhatsApp dependency;
- no incomplete WhatsApp navigation is visible in normal production;
- no queue/listener/scheduler may send WhatsApp traffic in DISABLED state.

After each normal FOOD release, refresh this branch from the new `main` and continue work.

## Runtime lifecycle

1. `DISABLED`
   - default;
   - code/config/data may exist;
   - no production outbound traffic;
   - operational UI hidden/fail-closed.

2. `INTERNAL_TEST`
   - allowlisted test numbers only;
   - used for engineering/QA.

3. `PILOT`
   - controlled pilot only after explicit pre-GA approval.

4. `GA_READY`
   - may only be set after #1407 certification passes.

5. `ENABLED`
   - explicit Super Admin production activation after GA_READY.

Independent emergency kill switch:
- immediately blocks new outbound sends;
- does not delete configuration, conversations or history.

## Architecture

Provider abstraction:
`WhatsAppProvider`

Initial provider:
`MetaCloudWhatsAppProvider`

Inbound:
`Webhook -> durable inbox -> normalized processor -> conversation -> agent inbox`

Outbound:
`FOOD event / agent action / campaign -> engagement engine -> queue -> provider`

Core FOOD controllers must not call provider APIs directly.

## Dynamic number/account model

A phone number is configuration, not application identity.

- multiple saved connection profiles;
- one primary outbound profile;
- profile test before activation;
- atomic primary switch;
- previous number may become RECEIVE_ONLY / DRAIN;
- history keeps the exact profile used per message;
- encrypted secrets;
- Dashboard-managed profile lifecycle;
- health and audit evidence.

## Work lanes

- #1400 — dark-launch foundation, lifecycle flags, kill switch and release isolation.
- #1401 — connection profiles, number switching, secrets and health.
- #1402 — provider abstraction, webhook ingestion, queues, send/receive, retry/idempotency.
- #1403 — agent RBAC, assignment, shared inbox and Customer 360.
- #1404 — transactional notification event engine.
- #1405 — templates, campaigns, segmentation, consent and opt-out.
- #1406 — supervisor analytics, SLA, leads, follow-ups and unified timeline.
- #1407 — independent certification and GA activation lock.

## Parallel execution

Start #1400 immediately.

After its contracts are stable:
- #1401 and #1403 can proceed in parallel.
- #1402 builds on #1401 connection contracts.
- #1404 and #1405 build on #1402 transport contracts and can then proceed in parallel.
- #1406 consumes agent + messaging/campaign data.
- #1407 runs only after all implementation lanes converge.

Workers use dedicated branches and target `feature/whatsapp-crm-dark-launch`, not the active FOOD release branch.

## Promotion policy

A worker issue can close when its own production-quality scope is complete, even though the overall module remains disabled.

Closing a child issue does **not** activate WhatsApp and does **not** make the module a release requirement.

Only #1407 can certify `GA_READY`.

## Final activation

After #1407 PASS:

1. refresh from latest `main`;
2. resolve and certify integration;
3. merge/promote the completed module through the normal release process;
4. leave production disabled by default;
5. Super Admin configures/tests a WhatsApp connection profile;
6. explicit activation changes lifecycle from GA_READY to ENABLED.

No source-code edit or redeployment is required merely to change the connected number or to enable/disable an already certified module.

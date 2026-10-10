# FOODEX Durable Decisions

This is a concise decision log for choices that should survive individual chats/tasks. It does not replace detailed contracts under `docs/`.

## D-001 — Live repository state outranks cached context
**Decision:** GitHub Issue/branch/PR/exact-head CI is authoritative for execution state. Chat history, `.ai/CURRENT_STATE.md`, mission registries and handoff summaries are orientation aids.

## D-002 — One atomic task owns one branch and one PR
**Decision:** Resume the same Issue/branch/PR across workers and sessions. Disconnects or red CI do not justify replacement branches.

## D-003 — Business state transitions stay server-authoritative
**Decision:** Order, finance, assignment, delivery and similar transitions are validated and authorized in the backend. Clients request transitions; they do not become the source of truth.

## D-004 — UI is business-first, not database-first
**Decision:** Dashboard uses business-domain navigation, meaningful lookups/builders and direct record actions. Internal IDs/raw JSON are not normal business inputs.
**Authority:** `docs/design-reference/DASHBOARD_UI_UX_CONTRACT.md`.

## D-005 — Localization is correctness
**Decision:** AR/EN parity, RTL/LTR and localized business values are product correctness requirements.
**Authority:** `docs/quality/LOCALIZATION_CONTRACT.md` and `AGENTS.md`.

## D-006 — Release artifacts share one lineage
**Decision:** Dashboard update assets plus Customer/Driver/Van artifacts and relevant Setup/fresh-install evidence must agree on release version/source lineage.
**Authority:** `docs/release/RELEASE_ARTIFACT_CONTRACT.md`.

## D-007 — Green CI is necessary but not universal product proof
**Decision:** Visual/interaction requirements require matching runtime evidence where promised. Closed Issue + generic green CI cannot substitute.

## D-008 — Repository-native AI context stays thin
**Decision:** `.ai/` summarizes durable knowledge and reusable playbooks while referencing authoritative contracts rather than copying them wholesale.

## Adding a decision
Append decision, Issue/PR/date when relevant, reason, consequences and authoritative contract. Do not add trivial implementation notes.

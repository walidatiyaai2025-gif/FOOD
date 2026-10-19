# FOODEX AI Knowledge / RAG Contract

Status: AI-15 / #2223  
Applies to: existing FOODEX Management Assistant only.

## Purpose

FOODEX may answer policy, procedure, SOP, FAQ, manual, product and approved business-knowledge questions from governed documents. This layer does not replace transactional tools, semantic metrics, analytics read models or domain services.

Routing is explicit:

- live/current metrics, orders, inventory, customers, drivers and operational facts -> existing typed FOODEX tools;
- approved document knowledge -> `knowledge.search`;
- hybrid questions -> the minimum required live tools plus `knowledge.search`.

No model receives database credentials or a generic SQL capability.

## Approved ingestion contract

`AssistantKnowledgeIndex::indexApproved()` is the internal authoritative ingestion boundary for AI-15. No public ingestion API is introduced by this Issue, so the OpenAPI surface is unchanged.

An approved document supplies:

- `source_key`: stable logical source identity;
- `source_type`: manual, policy, procedure, SOP, FAQ, product or business;
- `title`;
- exact `version`;
- `locale`: `ar` or `en`;
- optional `store_id`: null means globally approved, otherwise tenant/store scoped;
- document `content`;
- optional source URI, validity date and non-secret metadata.

Only indexed `approved` documents are eligible for retrieval. Re-indexing the same source/version replaces its chunks. Approving a newer version supersedes older approved versions for the same source, locale and scope and removes their active chunks.

## Lifecycle and revocation

The document lifecycle is server-owned:

`approved -> superseded | revoked | deleted`

- superseded, revoked and deleted versions are excluded from retrieval;
- active chunks are deleted when a source is superseded, revoked or deleted;
- an expired `valid_until` is excluded even if the row still exists for audit/history;
- re-indexing an approved version refreshes `indexed_at`, content hash and chunks.

The retained document row is lifecycle/audit metadata. It is not evidence once inactive.

## Scope and tenant isolation

Every Assistant execution still passes through `AssistantToolExecutor`, which reconstructs identity and store/channel authority server-side before the tool executes.

`knowledge.search` can see:

1. approved global documents; and
2. approved documents for the exact server-authorized active store.

A tenant-scoped document for another store is never a retrieval candidate. Tool arguments cannot supply or override `store_id`, permissions, actor identity, SQL or database connection fields.

## Retrieval and weak-evidence refusal

AI-15 uses a bounded deterministic retrieval baseline. Documents are normalized and chunked at ingestion; retrieval scores bounded candidates against normalized query terms and title/phrase matches.

Server-owned configuration controls:

- maximum results;
- maximum candidate set;
- minimum retrieval threshold;
- chunk size and overlap.

If no candidate reaches the configured threshold, the tool returns `answerable=false` and `NO_STRONG_EVIDENCE`; the Assistant must not manufacture an answer from model memory. Empty/weak queries are likewise refused.

This contract permits a later vector/embedding implementation only if it preserves the same scope, lifecycle, threshold, provenance, authorization and refusal invariants.

## Citations and freshness

Every accepted match returns source provenance including:

- public source id;
- source key/type/title;
- exact version;
- global vs tenant scope;
- source URI when approved;
- approval/index timestamps;
- validity timestamp.

The Assistant appends server-derived exact source/version citations to knowledge-backed answers. Provider-authored prose cannot remove or fabricate these citations.

Freshness is explicit through indexed/validity metadata. Expired sources are not presented as current evidence.

## Indirect prompt-injection boundary

Document content is untrusted data.

- Planning occurs before document excerpts exist, so document text cannot choose tools.
- Retrieved excerpts are marked `untrusted_document_text=true`.
- Synthesis receives zero callable tools.
- Synthesis instructions explicitly forbid following document instructions or widening permissions.
- Tool authorization is still rechecked server-side for every live or knowledge tool.
- A malicious document cannot turn a read into a write, expose another tenant, reveal hidden prompts or enable an unregistered tool.

## Degraded/provider-disabled behavior

Knowledge/RAG is additive to the existing Assistant. Provider failure or disabled AI continues to use the existing deterministic Assistant fallback and does not affect core FOODEX commerce. The knowledge tables have no request-global, scheduler or background dependency when unused.

## Acceptance evidence

Focused coverage in `AssistantKnowledgeRagTest` proves:

- global + tenant retrieval with cross-tenant exclusion;
- exact source/version citations;
- supersede/reindex lifecycle;
- immediate revoke/delete exclusion;
- expired and weak-evidence refusal;
- malicious document text cannot trigger additional tools or expand scope.

The existing Assistant typed-tool/security tests continue to cover centralized schema, permission, store/channel and audit enforcement.

# FOODEX Proactive Intelligence Center

Issue: #2226 (AI-18)  
Parent program: #2208  
Canonical runtime: existing Laravel Management Dashboard and existing FOODEX Assistant

## Purpose

The Intelligence Center turns deterministic, already-governed FOODEX findings into reviewable daily and weekly management briefs. It extends the existing Assistant path; it does not create another chatbot, another AI backend, or a model-owned analytics path.

The feature is intentionally useful with the external Ollama/OpenAI-compatible provider disabled. Laravel owns the numbers, finding lifecycle, thresholds, authorization, scheduling, persistence, delivery and audit trail.

## Runtime controls

The Center reuses AssistantRuntimeSettings.

- If the FOODEX Assistant is disabled, proactive intelligence authorization fails closed.
- If the Assistant read-only safety fence is disabled, proactive intelligence also fails closed.
- External AI/provider availability is not required for ingestion, briefing, viewing, acknowledgement, resolution or in-app delivery.

## Finding ingestion contract

AssistantIntelligenceService::upsertForUser is the stable ingestion seam for deterministic producers such as #2219, #2220 and #2222.

Each finding supplies:

- source_key and recipient-scoped dedupe_key;
- severity: info, opportunity, warning or critical;
- AR/EN title and narrative;
- deterministic evidence;
- snapshot_at and optional stale_after_at;
- optional approved proposed_action;
- optional FOODEX store/channel scope.

The service preserves acknowledgement/resolution state when the same dedupe key is refreshed. The database unique key on recipient_user_id + dedupe_key makes retries idempotent.

### Sell-out evidence

Any finding containing evidence.data_class=SELL_OUT must also contain:

- evidence.retailer_store_ids (or evidence.retailer_store_id);
- evidence.wholesale_store_id.

The ingestion path, Intelligence Center, brief composition and final dispatcher all re-check RetailerSellOutAccessPolicy. Missing consent metadata or revoked consent fails closed. This is a delivery-time check; consent captured when a finding was created is never treated as permanent authorization.

## Subscriptions and thresholds

Each recipient has a global intelligence subscription with:

- enabled / disabled;
- daily cadence;
- weekly cadence;
- minimum severity threshold;
- preferred AR/EN brief language;
- delivery_channel=in_app.

The minimum severity is the actionable threshold used when composing a brief. This deliberately avoids a second threshold engine: domain-specific anomaly/opportunity thresholds remain owned by deterministic producer services, while AI-18 owns recipient subscription and delivery thresholding.

Only the existing in-app Notification transport is used. AI-18 does not invent email, SMS, WhatsApp or push delivery.

## Brief generation

AssistantBriefService builds a bounded daily or weekly brief from fresh, unresolved findings that are still authorized for the recipient.

The stored payload includes:

- cadence and period;
- exact snapshot timestamp;
- finding count and evidence-bearing items;
- provider_required=false;
- numbers_authority=deterministic_software.

The idempotency key is derived from recipient, subscription scope, cadence, period and locale. Re-running the scheduler for the same logical brief returns the same brief row.

Stale findings are excluded from new briefs. Existing history still shows its stored snapshot timestamp so old data is not silently presented as current.

Finding and brief timestamps are normalized to UTC for persistence and authorization queries; user-facing schedule and notification presentation may render them in the configured operational timezone (Asia/Kuwait).

## Delivery-time authorization

AssistantBriefDispatcher reauthorizes immediately before creating the in-app Notification and again after locking the brief row.

It verifies:

1. active user;
2. current assistant.use permission;
3. current store/channel scope;
4. current authorization for every included finding;
5. current retailer sell-out consent when evidence is SELL_OUT.

If any check fails, the brief becomes blocked and no Notification is published.

## Lifecycle and audit

Findings support:

- unread;
- acknowledged;
- resolved.

The Center is recipient-scoped. Lifecycle changes reauthorize the exact finding before mutation and controller actions are audit logged. Subscription updates and manual brief generation are also audit logged.

## Routes and placement

Canonical UI:

- GET /admin/assistant/intelligence
- PUT /admin/assistant/intelligence/subscription
- POST /admin/assistant/intelligence/generate
- POST /admin/assistant/intelligence/findings/{finding}/acknowledge
- POST /admin/assistant/intelligence/findings/{finding}/resolve

Discoverability is composed into the existing Assistant settings surface rather than creating a duplicate top-level dashboard domain.

The UI is AR/EN and responsive.

## Scheduler

Existing Laravel scheduler only:

- foodex:assistant-intelligence-briefs daily --limit=200 at 07:00 Asia/Kuwait
- foodex:assistant-intelligence-briefs weekly --limit=200 on Monday at 07:15 Asia/Kuwait

Both use withoutOverlapping. Re-runs do not duplicate the brief or Notification because both use deterministic dedupe keys.

## Dependency integration

AI-18 does not duplicate the deterministic intelligence engines owned by dependencies.

- #2219 supplies governed wholesale executive findings.
- #2220 supplies retailer 360 findings, including SELL_OUT consent metadata when applicable.
- #2222 supplies anomaly/opportunity findings with deterministic evidence.
- #2224 may supply approved next-action drafts through proposed_action.

Those producers should call the ingestion seam after their own domain calculations. AI-18 remains the lifecycle, subscription, brief and delivery composition layer.

# FOODEX AI Safe Actions Contract

Issue: **#2224 / AI-16**  
Parent: **#2208**

## Authority

Safe actions extend the existing FOODEX Assistant. Provider/model output may propose an action, but it never authorizes or executes a business mutation. Laravel owns the action record, payload hash, scope, permission checks, expiry, explicit approval and execution.

The initial executable action is intentionally narrow:

- `procurement.purchase_request.create_draft`

It executes only through the existing `PurchaseRequestManager::create()` domain service and therefore produces a **draft purchase request only**. It never submits, approves, issues, receives, posts or pays anything.

## Lifecycle

```text
READ / deterministic analysis
    -> DRAFT approval record
    -> exact localized preview
    -> explicit user approval
    -> server re-resolves tenant context
    -> server re-authorizes permission
    -> payload + context integrity checks
    -> independent write-action kill switch
    -> idempotent EXECUTE through domain service
    -> audit + immutable result reference
```

A provider sentence such as "approved", remembered conversation text, a stale browser payload, or an old context token is never an approval.

## Action levels

- **READ** — existing typed read tools.
- **DRAFT** — creates only an Assistant approval record and preview; no business-domain mutation.
- **APPROVAL_REQUIRED** — business execution is blocked until explicit approval is recorded.
- **PROHIBITED** — any unregistered mutation, arbitrary SQL, arbitrary model-selected function, permission/scope override, or direct provider credential/database path.

## Preview contract

Every draft contains:

- action key/version;
- exact store/channel target;
- exact canonical payload;
- side effects and explicit non-effects;
- payload hash;
- server context hash;
- idempotency key;
- expiry.

The approval endpoint requires the exact reviewed payload hash. Any payload tampering fails with a conflict before execution.

## Re-authorization and replay safety

Approval and execution both re-resolve tenant/store context and re-check `procurement.purchase_requests.manage`. A permission loss, store/context change or expired approval fails closed.

Execution is idempotent. Repeating an already executed approval returns the original stored result rather than creating a duplicate purchase request.

## Conflict, compensation and rollback guidance

The first action intentionally creates a reversible **draft** purchase request. If the user changes intent after execution, the normal procurement workflow remains authoritative: review the draft and cancel it through the existing Purchase Request controls/service. The Assistant does not silently compensate, submit, approve, issue, or delete procurement records.

If execution fails before the domain service commits, the approval remains diagnosable and no retry may widen scope. If execution succeeded, its immutable result reference is returned on retries instead of creating a duplicate.

## Kill switch and disabled behavior

`assistant.security.write_actions_enabled` / `ASSISTANT_AI_WRITE_ACTIONS_ENABLED` is the final execution fence and defaults OFF from #2229. Draft/preview evidence may exist while execution is disabled. Ordinary FOODEX procurement and commerce continue through their existing routes/services regardless of Assistant/provider availability.

## Audit events

- `assistant.action.drafted`
- `assistant.action.approved`
- `assistant.action.executed`
- the authoritative domain service also emits `enterprise.purchase_request.created`

Audit data stores hashes, scope, status and result references; it does not treat model reasoning as evidence or authority.

## API surface

The existing authenticated Dashboard Assistant route family is extended with:

- `POST /admin/assistant/actions`
- `GET /admin/assistant/actions/{uuid}`
- `POST /admin/assistant/actions/{uuid}/approve`
- `POST /admin/assistant/actions/{uuid}/execute`

No public `/api/v1` contract is added.

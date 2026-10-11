# Skill: Flutter State Safety

Use for all FOODEX Flutter production changes.

## State authority
Identify one authoritative owner for each piece of mutable state. Do not mirror the same business value in several local booleans/controllers unless the values have different semantics.

## Explicit screen states
Live/data screens must distinguish relevant states explicitly:
- initial/loading;
- success;
- empty;
- error + retry;
- stale/offline/last-confirmed where the domain supports it.

Do not represent several mutually exclusive states through a loose boolean soup.

## Mutation rules
- make state transitions atomic from the user's perspective;
- disable/dedupe repeated destructive or financial submissions;
- never optimistically invent authoritative server state unless the existing contract explicitly supports optimistic UI;
- preserve server validation/errors instead of replacing them with generic success;
- on refresh, keep identity/scope/store/channel context intact.

## Derived values
Prefer deriving display values from the authoritative state instead of storing a second mutable copy. Keep money, quantity, status and permission semantics in shared formatters/contracts where those already exist.

## Failure behavior
A failed refresh must not silently erase previously confirmed data. A failed mutation must not leave the UI showing a completed state unless the server outcome is authoritative.

## Proof
Bug fixes need regression coverage at the smallest stable level: pure model/state test first where possible, widget/integration coverage where lifecycle or rendering behavior is part of the defect.

# Skill: Database Change

Use for migrations, backfills, constraints, indexes, data-shape transitions and destructive cleanup.

## Preflight
- Continue the existing Issue/branch/PR.
- Inspect current migrations/schema/models and real usage.
- Identify affected reads, writes, reports, exports, APIs and mobile clients.

## Design checklist
- Additive or destructive?
- How do existing production rows migrate?
- Are nullability/defaults intentional?
- Are unique/foreign-key constraints compatible with current data?
- Query/index impact?
- Large-table lock/backfill risk?
- Rollback/recovery path?
- Audit/ledger retention requirement?
- Cross-store/tenant isolation?

## Implementation
Prefer expand/backfill/switch/contract for risky transitions.

For cleanup:
- define exactly which records are owned by the deleted entity;
- preserve unrelated customer/order/financial history;
- wrap dependent writes in a transaction;
- reconcile surviving state explicitly;
- leave audit evidence when the business process requires it.

## Tests
Cover migration on representative existing data, new writes, legacy/nullable rows, constraints, isolation, recovery where practical and dependent API/UI behavior.

Never validate only against an empty database if production already has data.

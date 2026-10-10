# Skill: FOODEX Short-Prompt Intent Routing

Use when the owner gives a short UI request such as:
- `اعمل صفحة تحصيل للفان`
- `صفحة توريد للفان`
- `اعمل شاشة تتبع السواقين`
- `wholesale orders screen`

## Executable router

Run:

```bash
python3 scripts/foodex-ui-intent-plan.py \
  --intent "<owner request>" \
  [--surface dashboard|customer|driver|van] \
  [--case-id <known-case>] \
  [--strict] \
  [--output artifacts/foodex-ui-intent-plan.json]
```

The router combines:
- production-backed intent phrases;
- surface hints;
- page archetype;
- business recipe;
- route authority when registered;
- production sources/tests;
- context/quality/visual gates.

## Precedence

Intent inference never overrides:
1. explicit Issue scope;
2. explicit route/screen named by the owner;
3. current route authority;
4. explicit surface/app selection.

If the owner explicitly says Van App, do not route to Dashboard Van finance because both mention Van.

## Ambiguity rule

Do not invent behavior for weak prompts.

Examples:
- `اعمل صفحة تحصيل للفان` -> should resolve to `van.collection`;
- `اعمل صفحة توريد للفان` -> should resolve to `van.remittance`;
- `طلبات الجملة في تطبيق العميل` -> should resolve to `customer.wholesale-orders`;
- `صفحة طلبات` -> is intentionally too broad unless Issue/route context resolves it.

When unresolved/ambiguous, inspect live Issue/route context first. Ask the owner only if repository context cannot disambiguate.

## Completion

After intent resolution, continue through:
Business Journey -> Context Authority -> Route Authority -> UI Contract Planner -> Golden/Structural reference -> Implementation -> Audit/Evidence.

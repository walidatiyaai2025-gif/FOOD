# Skill: Flutter/Dart Authoring

Use for every Dart change under Customer, Driver or Van.

## 1. Inspect before writing
Read the target file, adjacent feature files, shared theme/components, existing services/models and relevant tests before creating a new abstraction. Prefer the established FOODEX pattern over a parallel architecture.

## 2. Keep the diff narrow
Change the authoritative layer only. Do not rewrite a whole screen/service to fix one defect. Do not add a dependency, state-management library, duplicate formatter, duplicate HTTP client, duplicate navigation shell or page-local design system when an existing FOODEX primitive can serve the requirement.

## 3. Dart type discipline
- preserve null-safety instead of forcing values with `!`;
- avoid `dynamic` at API/domain boundaries when a concrete type is known;
- model external nullable/optional data explicitly;
- keep public method return types explicit;
- prefer immutable values/final fields where lifecycle mutation is not required;
- do not silence analyzer defects with broad ignores.

## 4. Flutter build discipline
- `build()` is pure composition: no network calls, persistence, navigation, timers or repeated side effects;
- use `const` for stable widgets where practical, but never distort code merely to chase const;
- do not create controllers/notifiers/clients repeatedly inside `build()`;
- preserve current responsive/AR-EN/theme contracts.

## 5. Reuse project infrastructure
Use the existing API/session/auth/storage/localization/navigation/theme/state components. Production behavior must not gain mock/fake fallback data.

## 6. Validate before push
For every affected app:
1. run `bash scripts/flutter-changed-analysis.sh <app> <base> WORKTREE` from the repository context (the worker preflight calls it automatically);
2. keep changed Dart files canonically formatted;
3. pass fatal-info analysis on each changed Dart file plus the app-wide `flutter analyze`;
4. run focused tests when mapped;
5. run the normal hermetic test set;
6. run required visual/runtime evidence when the screen contract requires it.

Do not add `ignore` / `ignore_for_file` suppressions for lifecycle/async safety rules to make a changed file pass. Fix the ownership/lifecycle/type problem or document a narrowly approved exception in the task contract.

A worker must fix deterministic analyzer/test/format failures before pushing rather than using CI as the first discovery layer.

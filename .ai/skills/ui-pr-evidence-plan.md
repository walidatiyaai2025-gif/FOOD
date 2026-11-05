# FOODEX UI PR Coverage Plan

Use the exact-diff planner for affected surfaces, viewport/locale coverage and structural/functional requirements:

```bash
python3 scripts/foodex-ui-pr-plan.py --base <base-sha> --head <head-sha> --strict --output artifacts/foodex-ui-pr-evidence-plan.json
```

Record placement (Reuse → Extend → Compose → New Screen), route and discoverability, scope/permissions, AR/RTL and EN/LTR, desktop/tablet/mobile accessibility and functionality. Verify interactive states through relevant route, widget, integration and backend tests.

The viewport/locale matrix is reference metadata, **not** a screenshot inventory or mandatory proof workflow. A new screen no longer requires additions to capture scripts. `dashboard-visual-evidence`, `mobile-visual-evidence`, `app-preview-visual-parity` and `screenshot-evidence` are retired and must not block Required CI.

Manual screenshots are optional. Keep functional checks, security and release validation mandatory.

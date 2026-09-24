# Repository Structure

```text
foodex/
├── backend/                  # Laravel API + role-based admin web
├── apps/
│   ├── customer_app/        # Flutter iOS/Android
│   └── driver_app/          # Flutter iOS/Android
├── docs/
│   ├── architecture/
│   ├── api/
│   ├── database/
│   ├── business-rules/
│   ├── design-reference/
│   ├── installer/
│   ├── updater/
│   ├── workflows/
│   └── worker-rules/
├── packages/
│   ├── api_contracts/
│   ├── design_tokens/
│   └── shared_conventions/
├── scripts/
├── .github/
│   ├── workflows/
│   ├── ISSUE_TEMPLATE/
│   ├── pull_request_template.md
│   └── CODEOWNERS
├── README.md
├── CHANGELOG.md
└── VERSION
```

`main` is the only permanent branch. Issue branches are short-lived and deleted after squash merge.

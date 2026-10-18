# FOODEX 1.0.89 — Enterprise Dashboard, Customer Service & AI Provider Release Notes

## Release identity

- Dashboard / repository release: `1.0.89`.
- Customer source identity: `1.0.89+89`.
- Driver source identity: `1.0.89+89`.
- Van source identity: `1.0.89+89`.
- Versions `1.0.87` and `1.0.88` are intentionally unpublished; `1.0.89` is the owner-selected next immutable release identity.

## Included platform convergence

- Promote the complete authoritative main lineage after FOODEX 1.0.86.
- Complete Restaurant SaaS 2.0 management, template gallery/content binding, publishing validation and per-Retail-store entitlement.
- Complete enterprise Catalog/Product, Procurement, Stores & Channels, Field Operations, Notifications/WhatsApp operations, System Administration and Security governance surfaces.
- Add module-aware built-in job roles with entitlement-aware availability and protected canonical permission matrices.
- Add WhatsApp OTP management login using verified mobile identity with fail-closed runtime health and throttling.

## Customer Service and administration

- Complete Customer Service / Customer 360 operational workflows.
- Add Customer Service manager/team boundaries, order creation and auditable order-owner assignment while preserving Retail/B2B separation.
- Add audited user onboarding with initial-role assignment and compact task-focused Security tabs.
- Keep RBAC, tenant/store/channel scope and audit authority server-side.

## Dashboard UX

- Ship the compact Retail control-center dashboard and platform-only module isolation for Retail users.
- Restore one canonical shared Admin/B2B header, geometrically centered global search and responsive KPI density.
- Preserve Arabic/English and RTL/LTR behavior across the revised administration surfaces.

## FOODEX Assistant external AI configuration

- Add a fillable Settings Center page for an optional OpenAI-compatible provider.
- Configure full Chat Completions API URL, model, encrypted API key, timeout and saved-connection test from the Dashboard.
- Keep the deterministic FOODEX Assistant as the safe fallback.
- External AI may route only to the existing read-only Assistant tool allowlist; it cannot bypass RBAC, store/channel scope or write business data.

## Deployment and rollback

- The Dashboard update remains cumulative through the canonical updater boundary and does not require a full redeploy.
- Release promotion does not silently enable external AI, WhatsApp or other separately gated integrations.
- Use the normal FOODEX updater backup/rollback path if deployment health checks fail.

## Validation contract

- Repository Policy, Required CI, localization, backend tests, MySQL/Redis deployment acceptance, release validation, Update Bundle validation and required runtime evidence must pass on the exact release head.
- Release artifacts must be generated from the synchronized 1.0.89 source lineage and prior immutable releases must not be modified.

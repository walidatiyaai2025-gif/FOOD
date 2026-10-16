# FOODEX ERP Operational Runtime Certification — Issue #1914

Authority: #1914 under #1905 and final Enterprise certification #1497.

## Frozen repository candidate

- Certification base: `main@34f7febe914bf1ffcfd73250ebade7d4ee0e9bb5`.
- ERP UI implementation Issues #1906 through #1913 are merged and closed.
- Repository-local runtime entitlement blocker #1928 is merged and closed.
- Runtime recovery blocker #1933 is merged and closed.
- AdminNavigation performance blocker #1937 / PR #1938 is merged and closed on the certification base.
- This record adds no product feature or alternate source of truth.

## Mandatory runtime role journeys

The exact certification lineage must exercise the repository-verifiable portions of:

1. Procurement: supplier → PR → approval → PO → GRN → invoice handoff → supplier statement.
2. Warehouse: stock → transfer → count/adjustment → lot/expiry/reorder → movement provenance.
3. Accountant: source document → journal/posting → AR/AP ledger → aging/report.
4. Treasury: receipt/payment → approval → reconciliation → cash closing.
5. Customer service: customer → order → invoice/payment → delivery/conversation → activity.
6. Manager: ERP Center → My Work/Action Center → BI → canonical record drill-down.

## Required convergence evidence

Branch `test/1914-operational-completion-e2e` intentionally matches the repository final-convergence CI override. Exact-head certification therefore requires the applicable full set of:

- Repository Policy and changed-area resolution.
- Backend fast-fail and full backend tests.
- MySQL/Redis install, upgrade and recovery acceptance.
- Arabic/English localization quality.
- Operational-completion integrated E2E.
- Customer, Driver and Van validation because this is the explicit final-convergence lane.
- APP-PREVIEW/runtime distribution and applicable visual/runtime evidence.
- Tenant/store/warehouse authorization and disabled/not-entitled behavior already encoded by repository acceptance tests.
- Zero unresolved repository P0/P1 blockers.
- Enterprise performance comparison after #1937 must pass without threshold/baseline waiver.

## Production boundary

External cPanel/reference-host, representative real-pilot and equivalent human/external proof remain Production-only under the owner directive recorded by #1888. This certification does not fabricate, waive or replace those checks and does not authorize Production deployment or auto-enable customer modules.

## Closure rule

Issue #1914 may close only when this exact branch head is fully green for the repository-required final-convergence gates and no repository-local blocker remains. After merge, #1497 consumes this evidence for the ERP-UI delta recertification; #1905 and #1485 close only after their downstream completion conditions are satisfied.

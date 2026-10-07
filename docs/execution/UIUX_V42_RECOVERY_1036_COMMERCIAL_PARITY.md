# UIUX v4.2 Recovery — Commercial ↔ Van Parity Inventory (#1036)

Mission: **#1034 — UIUX-V42-RECOVERY**  
Owner: **#1036**  
Parity authority: `docs/design-reference/VAN_DASHBOARD_PARITY_CONTRACT.md`

This inventory records the Dashboard-side Commercial control plane required by the owner-approved Van production target.

| Van surface / capability | Commercial Dashboard counterpart | State |
|---|---|---|
| Product Catalog | Sales Control product configuration, selling-unit policy, availability and channel/store scoping | covered |
| Order Builder | authoritative selling units, break-pack policy, pricing/availability constraints and commercial flags | covered |
| Order Review | same server-authoritative commercial policy; no mobile-local pricing/rule authority introduced | covered |
| Orders | shared order/commercial state remains server authoritative; exact-record support is coordinated with #1035 where generic admin ownership applies | covered / shared ownership |
| Offers | Flash Offers under Marketing/Promotions with structured audience/product/channel authoring | covered |
| Wallet / custody commercial visibility | commercial limits/rules remain server authoritative; operational custody presentation that is not Commercial-owned stays with #1035/#1037 | covered / justified shared ownership |
| Collection / Receipt / Remittance | reconciliation/business-rule authority remains backend/Dashboard controlled; operational field workflow remains #1037/#1035 where applicable | covered / shared ownership |

## Parity controls

Commercial authoring preserves:
- store/channel scope;
- feature flags and permissions;
- quotas/availability;
- selling units and break-pack;
- audience targeting;
- product selection;
- audit/persistence behavior.

The Van application is not permitted to invent local pricing, selling-unit, availability, audience, collection, or remittance business truth to compensate for a missing Dashboard control.

Automated parity evidence: `CommercialDashboardContractTest::test_van_commercial_parity_is_explicit_in_sales_control_and_flash_offer_authoring`.

Final cross-app runtime validation remains owned by #1042.
